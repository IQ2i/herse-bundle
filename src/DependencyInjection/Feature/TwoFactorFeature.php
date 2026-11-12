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

use IQ2i\HerseBundle\DependencyInjection\LimiterConfiguration;
use IQ2i\HerseBundle\DependencyInjection\SharedServices;
use IQ2i\HerseBundle\DependencyInjection\Support;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\TwoFactor\AuthCodeMailer;
use IQ2i\HerseBundle\TwoFactor\SecondFactorThrottler;
use IQ2i\HerseBundle\TwoFactor\TrustedDeviceManager;
use IQ2i\HerseBundle\TwoFactor\TwoFactorEventRelay;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\EmailTwoFactorProvider;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManager as SchebTrustedDeviceManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The bridge with scheb/2fa-bundle: its defaults, the email of the code, the throttling and the trusted devices.
 *
 * @internal
 */
final class TwoFactorFeature
{
    public static function configure(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->enumNode('method')
                    ->info('The second factor: "email" (a code sent by email) or "totp" (an authenticator app). Required when the feature is enabled.')
                    ->values(['email', 'totp'])
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
     * @param array{users: array<class-string, array<string, mixed>>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, trusted_devices: array{enabled: bool}, two_factor: array{method?: 'email'|'totp', trusted_devices: bool, trusted_lifetime: string, throttling: array{enabled: bool, limit: int, interval: string, cache_pool: string, lock_factory: string|null}}} $config
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
            $defaults['email'] = ['enabled' => true, 'mailer' => 'iq2i_herse.two_factor.auth_code_mailer', 'digits' => 6];
        } else {
            $appName = null;
            foreach ($configs as $config) {
                $appName = \is_array($config['mailer'] ?? null) ? ($config['mailer']['app_name'] ?? $appName) : $appName;
            }
            $defaults['totp'] = ['enabled' => true] + (\is_string($appName) ? ['issuer' => $appName] : []);
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
