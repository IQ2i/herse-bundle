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
use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use IQ2i\HerseBundle\RateLimiter\LoginRateLimiter;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicyValidator;
use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

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

        foreach (self::FEATURES as $feature) {
            $node = $children->arrayNode($feature)->canBeEnabled();

            if ('password_policy' === $feature) {
                $this->configurePasswordPolicy($node);
            }

            if ('login_throttling' === $feature) {
                $this->configureLoginThrottling($node);
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
     *     users: array<class-string, array{type: string, email: string, id: string, plain_password?: array{property?: string|null, groups?: list<string>}}>,
     *     password_policy: array{enabled: bool, min_length: int, max_length: int, required_character_types: int, check_compromised: bool},
     *     login_throttling: array{enabled: bool, identifier_and_ip: array{limit: int, interval: string}, ip: array{limit: int, interval: string}, identifier: array{enabled: bool, limit: int, interval: string}, cache_pool: string, lock_factory: string|null},
     *     trusted_devices: array{enabled: bool},
     *     password_change_notification: array{enabled: bool},
     *     two_factor: array{enabled: bool},
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

        $children
            ->scalarNode('cache_pool')
                ->info('Cache pool storing the counters. It must be shared between servers on a multi-server project.')
                ->defaultValue('cache.rate_limiter')
                ->cannotBeEmpty()
            ->end()
            ->scalarNode('lock_factory')
                ->info('Lock factory service id, "auto" to use lock.factory when it exists, null to disable locking.')
                ->defaultValue('auto')
            ->end()
        ;
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

        $lockFactory = match ($throttling['lock_factory']) {
            null => null,
            'auto' => class_exists(LockFactory::class) ? new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
            default => new Reference($throttling['lock_factory']),
        };

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
