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

namespace IQ2i\HerseBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;

/**
 * The options and the wiring common to the rate limiters of the bundle.
 *
 * @internal
 */
final class LimiterConfiguration
{
    public static function addWindow(ArrayNodeDefinition $window, int $limit, string $interval): void
    {
        $window
            ->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->end()
                ->scalarNode('interval')
                    ->info('Duration of the window, as a relative date format (e.g. "1 minute").')
                    ->defaultValue($interval)
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (string $value): bool => !Support::isValidInterval($value))
                        ->thenInvalid('%s is not a valid interval.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    public static function addStorage(ArrayNodeDefinition $node): void
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

    public static function lockFactory(?string $lockFactory): ?Reference
    {
        return match ($lockFactory) {
            null => null,
            'auto' => class_exists(LockFactory::class) ? new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
            default => new Reference($lockFactory),
        };
    }
}
