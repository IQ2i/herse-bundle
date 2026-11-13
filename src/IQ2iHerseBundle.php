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

use IQ2i\HerseBundle\DependencyInjection\Compiler\PasswordPolicyLoaderPass;
use IQ2i\HerseBundle\DependencyInjection\Compiler\SecurityConfigurationPass;
use IQ2i\HerseBundle\DependencyInjection\Feature\LoginThrottlingFeature;
use IQ2i\HerseBundle\DependencyInjection\Feature\PasswordChangeNotificationFeature;
use IQ2i\HerseBundle\DependencyInjection\Feature\PasswordPolicyFeature;
use IQ2i\HerseBundle\DependencyInjection\Feature\TrustedDevicesFeature;
use IQ2i\HerseBundle\DependencyInjection\Feature\TwoFactorFeature;
use IQ2i\HerseBundle\DependencyInjection\SharedServices;
use IQ2i\HerseBundle\Mailer\Mailer;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

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
            ->arrayNode('firewalls')
                ->info('The firewalls the bundle protects, by firewall name. Their security configuration is checked when the container is built.')
                ->useAttributeAsKey('name')
                ->normalizeKeys(false)
                ->arrayPrototype()
                    ->treatNullLike([])
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path_prefix')
                            ->info('Prefix of the paths of the screens of the bundle on this firewall (e.g. "/admin"), which must match the pattern of the firewall.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $prefix): bool => !\is_string($prefix) || ('' !== $prefix && 1 !== preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $prefix)))
                                ->thenInvalid('%s is not a valid path prefix: it starts with a slash and does not end with one, e.g. "/admin".')
                            ->end()
                        ->end()
                        ->scalarNode('home')
                            ->info('Where the screens of the bundle lead once they are done, as a path or a route name. The path prefix by default.')
                            ->defaultNull()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('layout')
                            ->info('Template the screens of the bundle extend on this firewall, iq2i_herse.ui.layout by default.')
                            ->defaultNull()
                            ->cannotBeEmpty()
                        ->end()
                        ->booleanNode('login_throttling')
                            ->info('Require the firewall to use the login limiter of the bundle. Applies while login_throttling is enabled.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('two_factor')
                            ->info('Ask the second factor on this firewall: its routes are generated and its security configuration is checked. Applies while two_factor is enabled.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('verify')
                            ->info('Check the security configuration of the firewall. Disable it for a setup the checks do not understand.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        $children
            ->arrayNode('ui')
                ->info('The screens of the bundle: the second factor form and the enrollment of an authenticator app.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('layout')
                        ->info('Template the screens extend, with a "body" block. The layout of the bundle is a bare HTML page.')
                        ->defaultValue('@IQ2iHerse/layout.html.twig')
                        ->cannotBeEmpty()
                    ->end()
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

        PasswordPolicyFeature::configure($children->arrayNode('password_policy')->canBeEnabled());
        LoginThrottlingFeature::configure($children->arrayNode('login_throttling')->canBeEnabled());
        TrustedDevicesFeature::configure($children->arrayNode('trusted_devices')->canBeEnabled());
        PasswordChangeNotificationFeature::configure($children->arrayNode('password_change_notification')->canBeEnabled());
        TwoFactorFeature::configure($children->arrayNode('two_factor')->canBeEnabled());
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PasswordPolicyLoaderPass());
        $container->addCompilerPass(new SecurityConfigurationPass());
    }

    /**
     * @param array{
     *     users: array<class-string, array{type: string, email: string, id: string, password: string, locale: string|null, plain_password?: array{property?: string|null, groups?: list<string>}}>,
     *     firewalls: array<string, array{path_prefix: string, home: string|null, layout: string|null, login_throttling: bool, two_factor: bool, verify: bool}>,
     *     ui: array{layout: string},
     *     mailer: array{sender: string|null, sender_name: string|null, app_name: string|null},
     *     password_policy: array{enabled: bool, min_length: int, max_length: int, required_character_types: int, check_compromised: bool, hash_guard: bool, forms: array{auto: bool, help: bool}},
     *     login_throttling: array{enabled: bool, identifier_and_ip: array{limit: int, interval: string}, ip: array{limit: int, interval: string}, identifier: array{enabled: bool, limit: int, interval: string}, cache_pool: string, lock_factory: string|null},
     *     trusted_devices: array{enabled: bool, firewalls: array<string, array{cookie_name: string|null, lifetime: string}>, store_ip: bool, notify_first_device: bool, notify_before_second_factor: bool, revoke_on_password_change: bool},
     *     password_change_notification: array{enabled: bool, notify_on_other_user: bool, notify_on_console: bool},
     *     two_factor: array{enabled: bool, method?: 'email'|'totp', required: bool, trusted_devices: bool, trusted_lifetime: string, throttling: array{enabled: bool, limit: int, interval: string, cache_pool: string, lock_factory: string|null}},
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('iq2i_herse.users', $config['users']);
        $builder->setParameter('iq2i_herse.firewalls', $config['firewalls']);

        $features = [];
        foreach (self::FEATURES as $feature) {
            $features[$feature] = $config[$feature]['enabled'];
        }
        $builder->setParameter('iq2i_herse.features', $features);

        $passwordPolicyMapping = PasswordPolicyFeature::mapping($config['users']);
        if ([] !== $passwordPolicyMapping && !$config['password_policy']['enabled']) {
            throw new InvalidConfigurationException('iq2i_herse.users: "plain_password" requires iq2i_herse.password_policy to be enabled.');
        }

        if ($config['password_policy']['enabled']) {
            PasswordPolicyFeature::load($config['password_policy'], $passwordPolicyMapping, $container);
        }

        if ($config['login_throttling']['enabled']) {
            LoginThrottlingFeature::load($config['login_throttling'], $container);
        }

        if ($config['password_change_notification']['enabled']) {
            PasswordChangeNotificationFeature::load($config, $container);
        }

        if ($config['trusted_devices']['enabled']) {
            TrustedDevicesFeature::load($config, $container);
        }

        if ($config['two_factor']['enabled']) {
            TwoFactorFeature::load($config, $container, $builder);
        }

        SharedServices::loadUi($config, $container);
    }

    /**
     * The raw configurations are read here: they are not processed yet when the other extensions receive theirs.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $configs = $builder->getExtensionConfig($this->extensionAlias);

        TrustedDevicesFeature::prepend($builder, $configs);
        TwoFactorFeature::prepend($builder, $configs);
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
