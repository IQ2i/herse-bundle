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

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
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
            $children->arrayNode($feature)->canBeEnabled()->end();
        }
    }

    /**
     * @param array{
     *     users: array<string, array{type: string, email: string, id: string}>,
     *     password_policy: array{enabled: bool},
     *     login_throttling: array{enabled: bool},
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
