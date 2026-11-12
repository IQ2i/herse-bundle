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

namespace IQ2i\HerseBundle;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use IQ2i\HerseBundle\Command\PurgeTrustedDevicesCommand;
use IQ2i\HerseBundle\DependencyInjection\Compiler\PasswordPolicyLoaderPass;
use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\PasswordChange\AuthenticationWindow;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeListener;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification;
use IQ2i\HerseBundle\RateLimiter\LoginRateLimiter;
use IQ2i\HerseBundle\TrustedDevice\DeviceCookies;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceListener;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceRepository;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TrustedDevice\UserAgentParser;
use IQ2i\HerseBundle\TwoFactor\AuthCodeMailer;
use IQ2i\HerseBundle\TwoFactor\SecondFactorThrottler;
use IQ2i\HerseBundle\TwoFactor\TrustedDeviceManager;
use IQ2i\HerseBundle\TwoFactor\TwoFactorEventRelay;
use IQ2i\HerseBundle\User\UserResolver;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicyValidator;
use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\EmailTwoFactorProvider;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManager as SchebTrustedDeviceManager;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class IQ2iHerseBundle extends AbstractBundle
{
    /**
     * The security features, each one disabled until the project opts in.
     */
    public const FEATURES = [
        'password_policy',
        'login_throttling',
        'trusted_devices',
        'password_change_notification',
        'two_factor',
    ];

    protected string $extensionAlias = 'iq2i_herse';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $children = $rootNode->children();

        $children
            ->arrayNode('users')
                ->info('The project user classes, keyed by class name. A user is referred to by a neutral reference (type + identifier), never by a Doctrine relation.')
                ->useAttributeAsKey('class')
                ->normalizeKeys(false)
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('type')
                            ->info('Neutral name of the user population (e.g. "admin" or "shop"). Must be unique across the declared classes.')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('email')
                            ->info('Property path of the recipient email address, read with the PropertyAccess component.')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('id')
                            ->info('Property path of the user identifier.')
                            ->defaultValue('id')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('password')
                            ->info('Doctrine field holding the hashed password. Used by password_change_notification.')
                            ->defaultValue('password')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('locale')
                            ->info('Property path of the user locale, to translate the emails. The default locale of the application is used when it is not set.')
                            ->defaultNull()
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('plain_password')
                            ->info('Plain password property the password policy is applied to, as a property name or { property, groups }. Requires password_policy.')
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn (string $property): array => ['property' => $property])
                            ->end()
                            ->children()
                                ->scalarNode('property')->defaultNull()->cannotBeEmpty()->end()
                                ->arrayNode('groups')
                                    ->info('Validation groups the constraint belongs to (e.g. "sylius").')
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->defaultValue(['Default'])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $users): bool => [] !== self::findUnknownClasses($users))
                    ->then(static function (array $users): never {
                        throw new \InvalidArgumentException(\sprintf('iq2i_herse.users: class "%s" does not exist.', self::findUnknownClasses($users)[0]));
                    })
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $users): bool => [] !== self::findDuplicatedTypes($users))
                    ->then(static function (array $users): never {
                        throw new \InvalidArgumentException(\sprintf('iq2i_herse.users: the type "%s" is used by several classes, each class needs its own type.', self::findDuplicatedTypes($users)[0]));
                    })
                ->end()
            ->end()
        ;

        $children
            ->arrayNode('mailer')
                ->info('Shared by every email of the bundle.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('sender')
                        ->info('Sender email address. Required as soon as a feature sending emails is enabled.')
                        ->defaultNull()
                        ->cannotBeEmpty()
                    ->end()
                    ->scalarNode('sender_name')->info('Display name of the sender.')->defaultNull()->cannotBeEmpty()->end()
                    ->scalarNode('app_name')->info('Name of the application, available as "app_name" in the email templates.')->defaultNull()->cannotBeEmpty()->end()
                ->end()
            ->end()
        ;

        foreach (self::FEATURES as $feature) {
            $node = $children->arrayNode($feature)->canBeEnabled();

            if ('password_policy' === $feature) {
                $this->configurePasswordPolicy($node);
            }

            if ('login_throttling' === $feature) {
                $this->configureLoginThrottling($node);
            }

            if ('password_change_notification' === $feature) {
                $this->configurePasswordChangeNotification($node);
            }

            if ('trusted_devices' === $feature) {
                $this->configureTrustedDevices($node);
            }

            if ('two_factor' === $feature) {
                $this->configureTwoFactor($node);
            }

            $node->end();
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PasswordPolicyLoaderPass());
    }

    /**
     * @param array{
     *     users: array<class-string, array{type: string, email: string, id: string, password: string, locale: string|null, plain_password?: array{property?: string|null, groups?: list<string>}}>,
     *     mailer: array{sender: string|null, sender_name: string|null, app_name: string|null},
     *     password_policy: array{enabled: bool, min_length: int, max_length: int, required_character_types: int, check_compromised: bool},
     *     login_throttling: array{enabled: bool, identifier_and_ip: array{limit: int, interval: string}, ip: array{limit: int, interval: string}, identifier: array{enabled: bool, limit: int, interval: string}, cache_pool: string, lock_factory: string|null},
     *     trusted_devices: array{enabled: bool, firewalls: array<string, array{cookie_name: string|null, lifetime: string}>, store_ip: bool, notify_first_device: bool, notify_before_second_factor: bool, revoke_on_password_change: bool},
     *     password_change_notification: array{enabled: bool, notify_on_other_user: bool, notify_on_console: bool},
     *     two_factor: array{enabled: bool, method?: 'email'|'totp', trusted_devices: bool, throttling: array{enabled: bool, limit: int, interval: string, cache_pool: string, lock_factory: string|null}},
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('iq2i_herse.users', $config['users']);

        $features = [];
        foreach (self::FEATURES as $feature) {
            $features[$feature] = $config[$feature]['enabled'];
        }
        $builder->setParameter('iq2i_herse.features', $features);

        $passwordPolicyMapping = $this->passwordPolicyMapping($config['users']);
        if ([] !== $passwordPolicyMapping && !$config['password_policy']['enabled']) {
            throw new InvalidConfigurationException('iq2i_herse.users: "plain_password" requires iq2i_herse.password_policy to be enabled.');
        }

        if ($config['password_policy']['enabled']) {
            $this->loadPasswordPolicy($config['password_policy'], $passwordPolicyMapping, $container);
        }

        if ($config['login_throttling']['enabled']) {
            $this->loadLoginThrottling($config['login_throttling'], $container);
        }

        if ($config['password_change_notification']['enabled']) {
            $this->loadPasswordChangeNotification($config, $container);
        }

        if ($config['trusted_devices']['enabled']) {
            $this->loadTrustedDevices($config, $container);
        }

        if ($config['two_factor']['enabled']) {
            $this->loadTwoFactor($config, $container, $builder);
        }
    }

    /**
     * Maps the device entity of the bundle without a line in the Doctrine config of the project, and gives scheb the defaults of the method.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $configs = $builder->getExtensionConfig($this->extensionAlias);

        if ($builder->hasExtension('doctrine') && true === $this->rawFeature($configs, 'trusted_devices')['enabled']) {
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['IQ2iHerse' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => __DIR__.'/Entity',
                'prefix' => 'IQ2i\\HerseBundle\\Entity',
            ]]]]);
        }

        $this->prependScheb($builder, $configs);
    }

    /**
     * The values come first: the configuration of the project under scheb_two_factor overrides them.
     *
     * @param array<array<string, mixed>> $configs
     */
    private function prependScheb(ContainerBuilder $builder, array $configs): void
    {
        $twoFactor = $this->rawFeature($configs, 'two_factor');
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
     * The raw configurations are not processed yet: the last one setting an option wins, as when they are merged.
     *
     * @param array<array<string, mixed>> $configs
     *
     * @return array<string, mixed> the options, with "enabled" always set
     */
    private function rawFeature(array $configs, string $feature): array
    {
        /** @var array<string, mixed> $merged */
        $merged = ['enabled' => false];
        foreach ($configs as $config) {
            if (!\array_key_exists($feature, $config)) {
                continue;
            }

            $value = $config[$feature];
            /** @var array<string, mixed> $options */
            $options = \is_array($value) ? $value + ['enabled' => true] : ['enabled' => null === $value || (bool) $value];
            $merged = array_merge($merged, $options);
            $merged['enabled'] = (bool) $merged['enabled'];
        }

        return $merged;
    }

    private function configureTrustedDevices(ArrayNodeDefinition $node): void
    {
        $children = $node->children();

        $firewall = $children->arrayNode('firewalls')
            ->info('The firewalls whose logins register a device, by firewall name.')
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->arrayPrototype()
            ->treatNullLike([])
            ->addDefaultsIfNotSet()
        ;
        $firewall->children()
            ->scalarNode('cookie_name')
                ->info('Name of the device cookie, "herse_device_<firewall>" by default.')
                ->defaultNull()
                ->cannotBeEmpty()
                ->validate()
                    ->ifTrue(static fn (string $name): bool => 1 !== preg_match('/^[A-Za-z0-9_.-]+$/', $name))
                    ->thenInvalid('%s is not a valid cookie name.')
                ->end()
            ->end()
            ->scalarNode('lifetime')
                ->info('Inactivity after which the device is forgotten, as a relative date format (e.g. "30 days").')
                ->defaultValue('30 days')
                ->cannotBeEmpty()
                ->validate()
                    ->ifTrue(static fn (string $value): bool => !self::isValidInterval($value))
                    ->thenInvalid('%s is not a valid interval.')
                ->end()
            ->end()
        ;

        $children
            ->booleanNode('store_ip')
                ->info('Keep the last IP address of the device, for display. It is personal data: disable it to store none.')
                ->defaultTrue()
            ->end()
            ->booleanNode('notify_first_device')
                ->info('Warn the user, too, when the device is the first one registered for them (e.g. the first login after the installation).')
                ->defaultTrue()
            ->end()
            ->booleanNode('notify_before_second_factor')
                ->info('With two_factor, warn about a new device as soon as the password is accepted instead of once the second factor is passed. It warns the user that their password is known to a third party even when the second factor then fails.')
                ->defaultFalse()
            ->end()
            ->booleanNode('revoke_on_password_change')
                ->info('Revoke the devices of a user whose password changes, but the current one when the owner changes it. Requires password_change_notification.')
                ->defaultFalse()
            ->end()
        ;
    }

    /**
     * @param array{users: array<class-string, array<string, mixed>>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, trusted_devices: array{firewalls: array<string, array{cookie_name: string|null, lifetime: string}>, store_ip: bool, notify_first_device: bool, notify_before_second_factor: bool, revoke_on_password_change: bool}, password_change_notification: array{enabled: bool}} $config
     */
    private function loadTrustedDevices(array $config, ContainerConfigurator $container): void
    {
        $devices = $config['trusted_devices'];
        if ([] === $devices['firewalls']) {
            throw new InvalidConfigurationException('iq2i_herse.trusted_devices.firewalls requires at least one firewall.');
        }
        if ($devices['revoke_on_password_change'] && !$config['password_change_notification']['enabled']) {
            throw new InvalidConfigurationException('iq2i_herse.trusted_devices.revoke_on_password_change requires iq2i_herse.password_change_notification to be enabled.');
        }

        $this->assertEmailRequirements('trusted_devices', [
            'doctrine/orm' => EntityManagerInterface::class,
            'doctrine/doctrine-bundle' => DoctrineBundle::class,
        ]);

        $firewalls = [];
        foreach ($devices['firewalls'] as $name => $firewall) {
            $firewalls[$name] = ['cookie_name' => $firewall['cookie_name'] ?? 'herse_device_'.$name, 'lifetime' => $firewall['lifetime']];
        }

        $services = $container->services();

        $services->set('iq2i_herse.trusted_devices.cookies', DeviceCookies::class)
            ->args([$firewalls])
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onKernelResponse'])
            ->tag('kernel.reset', ['method' => 'reset'])
        ;
        $services->set('iq2i_herse.trusted_devices.repository', TrustedDeviceRepository::class)->args([new Reference('doctrine.orm.entity_manager')]);

        $services->set('iq2i_herse.trusted_devices.listener', TrustedDeviceListener::class)
            ->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.trusted_devices.repository'),
                new Reference('iq2i_herse.trusted_devices.cookies'),
                new Reference('iq2i_herse.trusted_devices.user_agent_parser'),
                new Reference('iq2i_herse.mailer'),
                new Reference('event_dispatcher'),
                new Reference('logger'),
                $devices['store_ip'],
                $devices['notify_first_device'],
                $devices['notify_before_second_factor'],
            ])
            // Before the trusted device listener of scheb: the device of a finished login must exist when scheb verifies it.
            ->tag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'priority' => 16])
        ;
        $services->set('iq2i_herse.trusted_devices.user_agent_parser', UserAgentParser::class);

        // Public: the projects list and revoke the devices through it.
        $services->set(TrustedDevices::class)
            ->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.trusted_devices.repository'),
                new Reference('iq2i_herse.trusted_devices.cookies'),
                new Reference('request_stack'),
            ])
            ->public()
        ;

        if (class_exists(Command::class)) {
            $services->set(PurgeTrustedDevicesCommand::class)->args([new Reference('iq2i_herse.trusted_devices.repository')])->tag('console.command');
        }

        $this->loadMailer($config, $container);
    }

    private function configurePasswordChangeNotification(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->booleanNode('notify_on_other_user')
                    ->info('Warn the account owner when another authenticated user (an administrator) changes the password.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('notify_on_console')
                    ->info('Warn the account owner when the password is changed outside of a web request (console command, worker).')
                    ->defaultTrue()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array{users: array<class-string, array{password: string}>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, password_change_notification: array{notify_on_other_user: bool, notify_on_console: bool}, trusted_devices: array{enabled: bool, revoke_on_password_change: bool}} $config
     */
    private function loadPasswordChangeNotification(array $config, ContainerConfigurator $container): void
    {
        $this->assertEmailRequirements('password_change_notification', [
            'doctrine/orm' => EntityManagerInterface::class,
            'doctrine/doctrine-bundle' => DoctrineBundle::class,
        ]);

        foreach ($config['users'] as $class => $user) {
            if (!property_exists($class, $user['password'])) {
                throw new InvalidConfigurationException(\sprintf('iq2i_herse.users: class "%s" has no "%s" property.', $class, $user['password']));
            }
        }

        $notification = $config['password_change_notification'];
        $services = $container->services();

        $services->set('iq2i_herse.password_change.authentication_window', AuthenticationWindow::class)
            ->tag('kernel.event_listener', ['event' => CheckPassportEvent::class, 'method' => 'open', 'priority' => 2048])
            ->tag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'close', 'priority' => -2048])
            ->tag('kernel.event_listener', ['event' => LoginFailureEvent::class, 'method' => 'close', 'priority' => -2048])
            ->tag('kernel.reset', ['method' => 'reset'])
        ;

        // Public: projects switch the notification off around fixtures or imports.
        $services->set(PasswordChangeNotification::class)->public();

        $services->set('iq2i_herse.password_change.listener', PasswordChangeListener::class)
            ->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.password_change.authentication_window'),
                new Reference(PasswordChangeNotification::class),
                new Reference('iq2i_herse.mailer'),
                new Reference('event_dispatcher'),
                new Reference('request_stack'),
                new Reference('security.token_storage'),
                new Reference('logger'),
                $notification['notify_on_other_user'],
                $notification['notify_on_console'],
                $config['trusted_devices']['enabled'] && $config['trusted_devices']['revoke_on_password_change'] ? new Reference(TrustedDevices::class) : null,
            ])
            ->tag('doctrine.event_listener', ['event' => 'onFlush', 'lazy' => true])
            ->tag('doctrine.event_listener', ['event' => 'postFlush', 'lazy' => true])
            ->tag('doctrine.event_listener', ['event' => 'onClear', 'lazy' => true])
        ;

        $this->loadMailer($config, $container);
    }

    /**
     * The services shared by the features sending emails.
     *
     * @param array{users: array<class-string, array<string, mixed>>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}} $config
     */
    private function loadMailer(array $config, ContainerConfigurator $container): void
    {
        if (null === $config['mailer']['sender']) {
            throw new InvalidConfigurationException('iq2i_herse.mailer.sender is required when a feature sending emails is enabled.');
        }

        $this->loadUserResolver($config, $container);
        $services = $container->services();
        $services->set('iq2i_herse.mailer', Mailer::class)->args([
            new Reference(MailerInterface::class),
            new Reference(TranslatorInterface::class),
            new Reference('logger'),
            $config['mailer']['sender'],
            $config['mailer']['sender_name'],
            $config['mailer']['app_name'],
        ]);
    }

    /**
     * @param array{users: array<class-string, array<string, mixed>>} $config
     */
    private function loadUserResolver(array $config, ContainerConfigurator $container): void
    {
        $container->services()->set('iq2i_herse.user_resolver', UserResolver::class)->args([$config['users'], new Reference('property_accessor')]);
    }

    /**
     * @param array<string, class-string> $extraPackages package => class, checked with the packages every email needs
     */
    private function assertEmailRequirements(string $feature, array $extraPackages): void
    {
        $missing = [];
        foreach ([
            'symfony/mailer' => MailerInterface::class,
            'symfony/twig-bundle' => TwigBundle::class,
            'symfony/translation' => Translator::class,
        ] + $extraPackages as $package => $class) {
            if (!ContainerBuilder::willBeAvailable($package, $class, ['iq2i/herse-bundle'])) {
                $missing[] = $package;
            }
        }

        if ([] !== $missing) {
            throw new InvalidConfigurationException(\sprintf('iq2i_herse.%s requires %s. Try running "composer require %s".', $feature, implode(', ', $missing), implode(' ', $missing)));
        }
    }

    private function configureTwoFactor(ArrayNodeDefinition $node): void
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
            ->end()
        ;

        $throttling = $node->children()->arrayNode('throttling')
            ->info('Wrong second factors allowed to a user on a firewall, whatever the IP. Requires symfony/rate-limiter.')
            ->canBeDisabled()
        ;
        $this->addThrottlingWindow($throttling, 5, '15 minutes');
        $this->addLimiterStorage($throttling);

        $node
            ->validate()
                ->ifTrue(static fn (array $twoFactor): bool => $twoFactor['enabled'] && !isset($twoFactor['method']))
                ->thenInvalid('iq2i_herse.two_factor.method is required: "email" or "totp".')
            ->end()
        ;
    }

    /**
     * @param array{users: array<class-string, array<string, mixed>>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, trusted_devices: array{enabled: bool}, two_factor: array{method?: 'email'|'totp', trusted_devices: bool, throttling: array{enabled: bool, limit: int, interval: string, cache_pool: string, lock_factory: string|null}}} $config
     */
    private function loadTwoFactor(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
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

        $this->loadUserResolver($config, $container);
        $services = $container->services();

        $services->set('iq2i_herse.two_factor.event_relay', TwoFactorEventRelay::class)
            ->args([new Reference('iq2i_herse.user_resolver'), new Reference('security.helper'), new Reference('event_dispatcher')])
            ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.success', 'method' => 'onSuccess'])
            ->tag('kernel.event_listener', ['event' => 'scheb_two_factor.authentication.failure', 'method' => 'onFailure'])
        ;

        if ('email' === $method) {
            $this->assertEmailRequirements('two_factor', []);
            $this->loadMailer($config, $container);
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
                $this->lockFactory($throttling['lock_factory']),
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
            ]);
        }
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

    private function configureLoginThrottling(ArrayNodeDefinition $node): void
    {
        $children = $node->children();

        $this->addThrottlingWindow($children->arrayNode('identifier_and_ip')->info('Failed attempts for the same identifier from the same IP.')->addDefaultsIfNotSet(), 5, '1 minute');
        $this->addThrottlingWindow($children->arrayNode('ip')->info('Failed attempts from the same IP, whatever the identifier.')->addDefaultsIfNotSet(), 25, '1 minute');
        $this->addThrottlingWindow(
            $children->arrayNode('identifier')
                ->info('Failed attempts for the same identifier, whatever the IP. It covers a distributed attack on one account, but lets a third party temporarily lock someone else out.')
                ->canBeEnabled(),
            20,
            '15 minutes',
        );

        $this->addLimiterStorage($node);
    }

    private function addLimiterStorage(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->scalarNode('cache_pool')
                    ->info('Cache pool storing the counters. It must be shared between servers on a multi-server project.')
                    ->defaultValue('cache.rate_limiter')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('lock_factory')
                    ->info('Lock factory service id, "auto" to use lock.factory when it exists, null to disable locking.')
                    ->defaultValue('auto')
                ->end()
            ->end()
        ;
    }

    private function lockFactory(?string $lockFactory): ?Reference
    {
        return match ($lockFactory) {
            null => null,
            'auto' => class_exists(LockFactory::class) ? new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
            default => new Reference($lockFactory),
        };
    }

    private function addThrottlingWindow(ArrayNodeDefinition $window, int $limit, string $interval): void
    {
        $window
            ->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->end()
                ->scalarNode('interval')
                    ->info('Duration of the window, as a relative date format (e.g. "1 minute").')
                    ->defaultValue($interval)
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (string $value): bool => !self::isValidInterval($value))
                        ->thenInvalid('%s is not a valid interval.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array{identifier_and_ip: array{limit: int, interval: string}, ip: array{limit: int, interval: string}, identifier: array{enabled: bool, limit: int, interval: string}, cache_pool: string, lock_factory: string|null} $throttling
     */
    private function loadLoginThrottling(array $throttling, ContainerConfigurator $container): void
    {
        if (!ContainerBuilder::willBeAvailable('symfony/rate-limiter', RateLimiterFactory::class, ['iq2i/herse-bundle'])) {
            throw new InvalidConfigurationException('iq2i_herse.login_throttling requires the Rate Limiter component. Try running "composer require symfony/rate-limiter".');
        }

        $lockFactory = $this->lockFactory($throttling['lock_factory']);

        $services = $container->services();
        $services->set('iq2i_herse.login_throttling.storage', CacheStorage::class)->args([new Reference($throttling['cache_pool'])]);

        $factories = [];
        foreach (['identifier_and_ip', 'ip', 'identifier'] as $name) {
            if (!($throttling[$name]['enabled'] ?? true)) {
                $factories[$name] = null;

                continue;
            }

            $id = 'iq2i_herse.login_throttling.limiter.'.$name;
            $services->set($id, RateLimiterFactory::class)->args([
                ['id' => 'iq2i_herse_login_'.$name, 'policy' => 'fixed_window', 'limit' => $throttling[$name]['limit'], 'interval' => $throttling[$name]['interval']],
                new Reference('iq2i_herse.login_throttling.storage'),
                $lockFactory,
            ]);
            $factories[$name] = new Reference($id);
        }

        // Public: it is referenced by id from the firewall "login_throttling.limiter" option of the project.
        $services->set('iq2i_herse.login_throttling.limiter', LoginRateLimiter::class)
            ->args([$factories['identifier_and_ip'], $factories['ip'], $factories['identifier'], new Reference('security.helper'), new Reference('event_dispatcher'), '%kernel.secret%'])
            ->tag('kernel.event_listener', ['event' => LoginFailureEvent::class, 'method' => 'onLoginFailure'])
            ->public()
        ;
    }

    private function configurePasswordPolicy(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->integerNode('min_length')->defaultValue(12)->min(1)->end()
                ->integerNode('max_length')
                    ->info('Upper bound, so that oversized inputs are never hashed.')
                    ->defaultValue(PasswordHasherInterface::MAX_PASSWORD_LENGTH)
                    ->min(1)
                ->end()
                ->integerNode('required_character_types')
                    ->info('How many of the 4 types (lowercase, uppercase, digit, special) are required, 0 to disable the rule.')
                    ->defaultValue(4)
                    ->min(0)
                    ->max(4)
                ->end()
                ->booleanNode('check_compromised')
                    ->info('Reject passwords leaked in a data breach, with the native NotCompromisedPassword constraint.')
                    ->defaultFalse()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $policy): bool => $policy['max_length'] < $policy['min_length'])
                ->thenInvalid('iq2i_herse.password_policy: max_length must be greater than or equal to min_length.')
            ->end()
        ;
    }

    /**
     * @param array{enabled: bool, min_length: int, max_length: int, required_character_types: int, check_compromised: bool} $policy
     * @param array<class-string, array{property: string, groups: list<string>}>                                             $mapping
     */
    private function loadPasswordPolicy(array $policy, array $mapping, ContainerConfigurator $container): void
    {
        if ($policy['check_compromised'] && !ContainerBuilder::willBeAvailable('symfony/http-client', HttpClient::class, ['iq2i/herse-bundle'])) {
            throw new InvalidConfigurationException('iq2i_herse.password_policy.check_compromised requires the HTTP client. Try running "composer require symfony/http-client".');
        }

        $services = $container->services();

        $services->set(PasswordPolicyValidator::class)
            ->args([$policy['min_length'], $policy['max_length'], $policy['required_character_types'], $policy['check_compromised']])
            ->tag('validator.constraint_validator', ['alias' => PasswordPolicy::VALIDATOR_SERVICE])
        ;

        if ([] !== $mapping) {
            $services->set(PasswordPolicyLoader::class)->args([$mapping]);
        }

        if (ContainerBuilder::willBeAvailable('symfony/form', AbstractType::class, ['iq2i/herse-bundle'])) {
            $services->set(PasswordPolicyType::class)->tag('form.type');
        }
    }

    /**
     * @param array<class-string, array{plain_password?: array{property?: string|null, groups?: list<string>}}> $users
     *
     * @return array<class-string, array{property: string, groups: list<string>}>
     */
    private function passwordPolicyMapping(array $users): array
    {
        $mapping = [];
        foreach ($users as $class => $user) {
            $property = $user['plain_password']['property'] ?? null;
            if (null === $property) {
                continue;
            }

            if (!property_exists($class, $property)) {
                throw new InvalidConfigurationException(\sprintf('iq2i_herse.users: class "%s" has no "%s" property.', $class, $property));
            }

            $mapping[$class] = ['property' => $property, 'groups' => $user['plain_password']['groups'] ?? ['Default']];
        }

        return $mapping;
    }

    // Mirrors how RateLimiterFactory parses the interval, which fails on the first use otherwise.
    private static function isValidInterval(string $interval): bool
    {
        try {
            new \DateTimeImmutable('+'.$interval);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * @param array<mixed> $users
     *
     * @return list<string>
     */
    private static function findUnknownClasses(array $users): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys($users)),
            static fn (string $class): bool => !class_exists($class),
        ));
    }

    /**
     * @param array<mixed> $users
     *
     * @return list<string>
     */
    private static function findDuplicatedTypes(array $users): array
    {
        /** @var list<string> $types */
        $types = array_column($users, 'type');

        return array_map(
            strval(...),
            array_keys(array_filter(array_count_values($types), static fn (int $count): bool => $count > 1)),
        );
    }
}
