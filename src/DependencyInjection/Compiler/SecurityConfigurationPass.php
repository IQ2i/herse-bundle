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

namespace IQ2i\HerseBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Refuses to build the container while a declared firewall misses the security configuration a feature relies on.
 *
 * The bundle never writes the security configuration of the project: it reads what the security extension built from it.
 *
 * @internal
 */
final class SecurityConfigurationPass implements CompilerPassInterface
{
    private const LIMITER = 'iq2i_herse.login_throttling.limiter';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('iq2i_herse.firewalls')) {
            return;
        }

        /** @var array<string, array{login_throttling: bool, verify: bool}> $firewalls */
        $firewalls = $container->getParameter('iq2i_herse.firewalls');
        /** @var array<string, bool> $features */
        $features = $container->getParameter('iq2i_herse.features');

        foreach ($firewalls as $name => $firewall) {
            if (!$firewall['verify']) {
                continue;
            }

            if (!$container->hasDefinition('security.firewall.map.context.'.$name)) {
                throw new InvalidConfigurationException(\sprintf('iq2i_herse.firewalls: the firewall "%s" is not declared in the security configuration.', $name));
            }

            if ($features['login_throttling'] && $firewall['login_throttling']) {
                $this->assertLoginLimiter($container, $name);
            }
        }
    }

    private function assertLoginLimiter(ContainerBuilder $container, string $firewall): void
    {
        $listener = 'security.listener.login_throttling.'.$firewall;
        $limiter = $container->hasDefinition($listener) ? $container->getDefinition($listener)->getArgument(1) : null;
        if (!$limiter instanceof Reference || self::LIMITER !== (string) $limiter) {
            throw new InvalidConfigurationException(\sprintf(<<<'MESSAGE'
                The firewall "%1$s" does not use the login limiter of Herse. Add it to the security configuration:

                    security:
                        firewalls:
                            %1$s:
                                login_throttling:
                                    limiter: %2$s

                Or set iq2i_herse.firewalls.%1$s.login_throttling to false to leave this firewall out.
                MESSAGE, $firewall, self::LIMITER));
        }

        // The security extension silently drops them as soon as a limiter is set.
        /** @var array<string, mixed> $config */
        foreach ($container->getExtensionConfig('security') as $config) {
            $firewalls = \is_array($config['firewalls'] ?? null) ? $config['firewalls'] : [];
            $throttling = \is_array($firewalls[$firewall] ?? null) ? $firewalls[$firewall]['login_throttling'] ?? null : null;
            $ignored = \is_array($throttling) ? array_intersect(['max_attempts', 'interval'], array_keys($throttling)) : [];
            if ([] !== $ignored) {
                throw new InvalidConfigurationException(\sprintf('The firewall "%s" sets both "login_throttling.limiter" and "login_throttling.%s": the latter is ignored, remove it and configure the limits in iq2i_herse.login_throttling.', $firewall, implode('", "login_throttling.', $ignored)));
            }
        }
    }
}
