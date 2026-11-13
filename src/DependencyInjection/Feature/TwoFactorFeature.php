<?php

declare(strict_types=1);

/*
 * This file is part of the Herse Bundle.
 *
 * (c) Loïc Sapone <loic@sapone.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IQ2i\HerseBundle\DependencyInjection\Feature;

use BaconQrCode\Writer;
use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use IQ2i\HerseBundle\Bridge\EasyAdmin\ResetTwoFactorAction;
use IQ2i\HerseBundle\Command\ResetTwoFactorCommand;
use IQ2i\HerseBundle\Controller\TotpEnrollmentController;
use IQ2i\HerseBundle\Controller\TwoFactorResetController;
use IQ2i\HerseBundle\DependencyInjection\LimiterConfiguration;
use IQ2i\HerseBundle\DependencyInjection\SharedServices;
use IQ2i\HerseBundle\DependencyInjection\Support;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TwoFactor\AuthCodeMailer;
use IQ2i\HerseBundle\TwoFactor\EnrollmentListener;
use IQ2i\HerseBundle\TwoFactor\QrCode\BaconQrCodeRenderer;
use IQ2i\HerseBundle\TwoFactor\QrCode\QrCodeRendererInterface;
use IQ2i\HerseBundle\TwoFactor\SecondFactorThrottler;
use IQ2i\HerseBundle\TwoFactor\TrustedDeviceManager;
use IQ2i\HerseBundle\TwoFactor\TwoFactorEventRelay;
use IQ2i\HerseBundle\TwoFactor\TwoFactorResetter;
use IQ2i\HerseBundle\User\UserLoader;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\EmailTwoFactorProvider;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManager as SchebTrustedDeviceManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The bridge with scheb/2fa-bundle: its defaults, the email of the code, the throttling and the trusted devices.
 *
 * @internal
 */
final class TwoFactorFeature
{
    private const FORM_TEMPLATE = '@IQ2iHerse/two_factor/form.html.twig';

    public static function configure(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->enumNode('method')
                    ->info('The second factor: "email" (a code sent by email) or "totp" (an authenticator app). Required when the feature is enabled.')
                    ->values(['email', 'totp'])
                ->end()
                ->booleanNode('required')
                    ->info('With totp, send the users without an authenticator app to the enrollment screen on the declared firewalls, until they have one. With email, every user of a class using the trait of the bundle has the second factor.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('reset_role')
                    ->info('With totp, the role allowed to reset the second factor of a user (e.g. ROLE_ADMIN) from the screen of the bundle. No such screen without it.')
                    ->defaultNull()
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('trusted_devices')
                    ->info('Skip the second factor on a device that passed it and was trusted by the user, with the device registry in place of the cookie of scheb. Requires trusted_devices and scheb/2fa-trusted-device.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('trusted_lifetime')
                    ->info('With trusted_devices, how long a verified device skips the second factor, as a relative date format (e.g. "30 days"). Counted from the verification: the logins do not extend it.')
                    ->defaultValue('30 days')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (string $value): bool => !Support::isValidInterval($value))
                        ->thenInvalid('%s is not a valid interval.')
                    ->end()
                ->end()
            ->end()
        ;

        $throttling = $node->children()->arrayNode('throttling')
            ->info('Wrong second factors allowed to a user on a firewall, whatever the IP. Requires symfony/rate-limiter.')
            ->canBeDisabled()
        ;
        LimiterConfiguration::addWindow($throttling, 5, '15 minutes');
        LimiterConfiguration::addStorage($throttling);

        $node
            ->validate()
                ->ifTrue(static fn (array $twoFactor): bool => $twoFactor['enabled'] && !isset($twoFactor['method']))
                ->thenInvalid('iq2i_herse.two_factor.method is required: "email" or "totp".')
            ->end()
        ;
    }

    /**
     * @param array{users: array<class-string, array<string, mixed>>, firewalls: array<string, mixed>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, trusted_devices: array{enabled: bool}, two_factor: array{method?: 'email'|'totp', required: bool, reset_role: string|null, trusted_devices: bool, trusted_lifetime: string, throttling: array{enabled: bool, limit: int, interval: string, cache_pool: string, lock_factory: string|null}}} $config
     */
    public static function load(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $method = $config['two_factor']['method'] ?? throw new InvalidConfigurationException('iq2i_herse.two_factor.method is required: "email" or "totp".');
        $bridge = $config['two_factor']['trusted_devices'];

        $missing = self::findMissingSchebPackages($method, $bridge);
        if ([] !== $missing) {
            throw new InvalidConfigurationException(\sprintf('iq2i_herse.two_factor requires %s. Try running "composer require %s".', implode(', ', $missing), implode(' ', $missing)));
        }

        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        if (!\is_array($bundles) || !\in_array(SchebTwoFactorBundle::class, $bundles, true)) {
            throw new InvalidConfigurationException(\sprintf('iq2i_herse.two_factor requires the bundle "%s" to be registered in config/bundles.php.', SchebTwoFactorBundle::class));
        }

        if ($bridge && !$config['trusted_devices']['enabled']) {
            throw new InvalidConfigurationException('iq2i_herse.two_factor.trusted_devices requires iq2i_herse.trusted_devices to be enabled.');
        }

        SharedServices::loadUserResolver($config, $container);
        $services = $container->services();

        $services->set('iq2i_herse.two_factor.event_relay', TwoFactorEventRelay::class)
            ->args([new Reference('iq2i_herse.user_resolver'), new Reference('security.helper'), new Reference('event_dispatcher')])
            ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.success', 'method' => 'onSuccess'])
            ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.failure', 'method' => 'onFailure'])
        ;

        if ('email' === $method) {
            SharedServices::assertEmailRequirements('two_factor', []);
            SharedServices::loadMailer($config, $container);
            $services->set('iq2i_herse.two_factor.auth_code_mailer', AuthCodeMailer::class)
                ->args([new Reference('iq2i_herse.user_resolver'), new Reference('iq2i_herse.mailer'), new Reference('logger')])
            ;
        }

        $throttling = $config['two_factor']['throttling'];
        if ($throttling['enabled']) {
            if (!ContainerBuilder::willBeAvailable('symfony/rate-limiter', RateLimiterFactory::class, ['iq2i/herse-bundle'])) {
                throw new InvalidConfigurationException('iq2i_herse.two_factor.throttling requires the Rate Limiter component. Try running "composer require symfony/rate-limiter".');
            }

            $services->set('iq2i_herse.two_factor.throttling.storage', CacheStorage::class)->args([new Reference($throttling['cache_pool'])]);
            $services->set('iq2i_herse.two_factor.throttling.limiter', RateLimiterFactory::class)->args([
                ['id' => 'iq2i_herse_two_factor', 'policy' => 'fixed_window', 'limit' => $throttling['limit'], 'interval' => $throttling['interval']],
                new Reference('iq2i_herse.two_factor.throttling.storage'),
                LimiterConfiguration::lockFactory($throttling['lock_factory']),
            ]);
            $services->set('iq2i_herse.two_factor.throttler', SecondFactorThrottler::class)
                ->args([new Reference('iq2i_herse.two_factor.throttling.limiter'), new Reference('iq2i_herse.user_resolver'), new Reference('security.helper'), new Reference('event_dispatcher'), '%kernel.secret%'])
                ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.attempt', 'method' => 'onAttempt'])
                ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.failure', 'method' => 'onFailure'])
                ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.success', 'method' => 'onSuccess'])
            ;
        }

        if ('totp' === $method) {
            self::loadReset($services, $config, [] !== $config['firewalls'] ? $config['two_factor']['reset_role'] : null);
        }

        if ('totp' === $method && [] !== $config['firewalls']) {
            self::loadEnrollment($services, $config['two_factor']['required'], $throttling['enabled'], $bridge);
        }

        if ($bridge) {
            $services->set('iq2i_herse.two_factor.trusted_device_manager', TrustedDeviceManager::class)->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.trusted_devices.repository'),
                new Reference('iq2i_herse.trusted_devices.cookies'),
                new Reference('request_stack'),
                $config['two_factor']['trusted_lifetime'],
            ]);
        }
    }

    /**
     * The screen enrolling an authenticator app on the declared firewalls, and the listener making it mandatory.
     */
    private static function loadEnrollment(ServicesConfigurator $services, bool $required, bool $throttled, bool $bridge): void
    {
        if (class_exists(Writer::class)) {
            $services->set('iq2i_herse.two_factor.qr_code_renderer', BaconQrCodeRenderer::class);
            // An alias of the project to its own renderer replaces this one.
            $services->alias(QrCodeRendererInterface::class, 'iq2i_herse.two_factor.qr_code_renderer');
        }

        // Public: the route of the enrollment screen names it.
        $services->set(TotpEnrollmentController::class)
            ->args([
                new Reference('iq2i_herse.firewall_registry'),
                new Reference('security.helper'),
                new Reference('scheb_two_factor.security.totp_authenticator'),
                new Reference('scheb_two_factor.persister'),
                new Reference('security.csrf.token_manager'),
                new Reference('twig'),
                new Reference('security.logout_url_generator'),
                new Reference('event_dispatcher'),
                new Reference('iq2i_herse.user_resolver'),
                $throttled ? new Reference('iq2i_herse.two_factor.throttler') : null,
                new Reference(QrCodeRendererInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $bridge ? new Reference('scheb_two_factor.trusted_device_manager') : null,
            ])
            ->public()
        ;

        if ($required) {
            $services->set('iq2i_herse.two_factor.enrollment_listener', EnrollmentListener::class)
                ->args([new Reference('iq2i_herse.firewall_registry'), new Reference('security.token_storage'), new Reference('router')])
                // Right after the firewall, which loads the user and answers a logout by itself.
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => 7])
            ;
        }
    }

    /**
     * What removes the authenticator app of a user: a service, a console command and, with a role, a screen.
     *
     * @param array{users: array<class-string, array<string, mixed>>, trusted_devices: array{enabled: bool}} $config
     */
    private static function loadReset(ServicesConfigurator $services, array $config, ?string $role): void
    {
        // Public: the projects reset a second factor from their own screens through it.
        $services->set(TwoFactorResetter::class)
            ->args([
                new Reference('scheb_two_factor.persister'),
                new Reference('iq2i_herse.user_resolver'),
                new Reference('event_dispatcher'),
                $config['trusted_devices']['enabled'] ? new Reference(TrustedDevices::class) : null,
            ])
            ->public()
        ;

        if (!interface_exists(ManagerRegistry::class)) {
            return;
        }

        $services->set('iq2i_herse.user_loader', UserLoader::class)->args([$config['users'], new Reference('doctrine')]);

        if (class_exists(Command::class)) {
            $services->set(ResetTwoFactorCommand::class)->args([new Reference('iq2i_herse.user_loader'), new Reference(TwoFactorResetter::class)])->tag('console.command');
        }

        if (null === $role) {
            return;
        }

        // Public: the route of the reset screen names it.
        $services->set(TwoFactorResetController::class)
            ->args([
                new Reference('iq2i_herse.firewall_registry'),
                new Reference('security.helper'),
                new Reference('iq2i_herse.user_loader'),
                new Reference(TwoFactorResetter::class),
                new Reference('security.csrf.token_manager'),
                new Reference('twig'),
                $role,
                new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->public()
        ;

        if (class_exists(Action::class)) {
            $services->set(ResetTwoFactorAction::class)->args([
                new Reference('iq2i_herse.firewall_registry'),
                new Reference('iq2i_herse.user_resolver'),
                new Reference('router'),
                new Reference('request_stack'),
                new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
        }
    }

    /**
     * The values come first: the configuration of the project under scheb_two_factor overrides them.
     *
     * @param array<array<string, mixed>> $configs
     */
    public static function prepend(ContainerBuilder $builder, array $configs): void
    {
        $twoFactor = Support::rawFeature($configs, 'two_factor');
        $method = $twoFactor['method'] ?? null;
        $bridge = (bool) ($twoFactor['trusted_devices'] ?? false);
        // A missing package is reported with its Composer command when the extension is loaded.
        if (!$twoFactor['enabled'] || !\in_array($method, ['email', 'totp'], true) || !$builder->hasExtension('scheb_two_factor') || [] !== self::findMissingSchebPackages($method, $bridge)) {
            return;
        }

        $defaults = [];
        if ('email' === $method) {
            $defaults['email'] = ['enabled' => true, 'mailer' => 'iq2i_herse.two_factor.auth_code_mailer', 'digits' => 6, 'template' => self::FORM_TEMPLATE];
        } else {
            $appName = null;
            foreach ($configs as $config) {
                $appName = \is_array($config['mailer'] ?? null) ? ($config['mailer']['app_name'] ?? $appName) : $appName;
            }
            $defaults['totp'] = ['enabled' => true, 'template' => self::FORM_TEMPLATE] + (\is_string($appName) ? ['issuer' => $appName] : []);
        }

        if ($bridge) {
            $defaults['trusted_device'] = ['enabled' => true, 'manager' => 'iq2i_herse.two_factor.trusted_device_manager'];
        }

        $builder->prependExtensionConfig('scheb_two_factor', $defaults);
    }

    /**
     * Concrete classes are checked: an interface that is not loaded yet is reported as missing.
     *
     * @return list<string> the Composer packages to install
     */
    private static function findMissingSchebPackages(string $method, bool $trustedDevices): array
    {
        $packages = [
            'scheb/2fa-bundle' => SchebTwoFactorBundle::class,
            'email' === $method ? 'scheb/2fa-email' : 'scheb/2fa-totp' => 'email' === $method ? EmailTwoFactorProvider::class : TotpAuthenticator::class,
        ];
        if ($trustedDevices) {
            $packages['scheb/2fa-trusted-device'] = SchebTrustedDeviceManager::class;
        }

        $missing = [];
        foreach ($packages as $package => $class) {
            if (!ContainerBuilder::willBeAvailable($package, $class, ['iq2i/herse-bundle'])) {
                $missing[] = $package;
            }
        }

        return $missing;
    }
}
