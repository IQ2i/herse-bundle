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
use IQ2i\HerseBundle\RateLimiter\LoginRateLimiter;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * The login limiter plugged in the native "login_throttling" option of the firewalls.
 *
 * @internal
 */
final class LoginThrottlingFeature
{
    public static function configure(ArrayNodeDefinition $node): void
    {
        $children = $node->children();

        LimiterConfiguration::addWindow($children->arrayNode('identifier_and_ip')->info('Failed attempts for the same identifier from the same IP.')->addDefaultsIfNotSet(), 5, '1 minute');
        LimiterConfiguration::addWindow($children->arrayNode('ip')->info('Failed attempts from the same IP, whatever the identifier.')->addDefaultsIfNotSet(), 25, '1 minute');
        LimiterConfiguration::addWindow(
            $children->arrayNode('identifier')
                ->info('Failed attempts for the same identifier, whatever the IP. It covers a distributed attack on one account, but lets a third party temporarily lock someone else out.')
                ->canBeEnabled(),
            20,
            '15 minutes',
        );

        LimiterConfiguration::addStorage($node);
    }

    /**
     * @param array{identifier_and_ip: array{limit: int, interval: string}, ip: array{limit: int, interval: string}, identifier: array{enabled: bool, limit: int, interval: string}, cache_pool: string, lock_factory: string|null} $throttling
     */
    public static function load(array $throttling, ContainerConfigurator $container): void
    {
        if (!ContainerBuilder::willBeAvailable('symfony/rate-limiter', RateLimiterFactory::class, ['iq2i/herse-bundle'])) {
            throw new InvalidConfigurationException('iq2i_herse.login_throttling requires the Rate Limiter component. Try running "composer require symfony/rate-limiter".');
        }

        $lockFactory = LimiterConfiguration::lockFactory($throttling['lock_factory']);

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
}
