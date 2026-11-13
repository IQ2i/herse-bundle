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

use IQ2i\HerseBundle\Routing\HerseRouteLoader;
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
    private const IN_PROGRESS = 'IS_AUTHENTICATED_2FA_IN_PROGRESS';
    // A rule carrying one of these depends on the request: whether it applies cannot be told here.
    private const CONDITIONS = ['host', 'port', 'ips', 'methods', 'attributes', 'route', 'request_matcher', 'allow_if'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('iq2i_herse.firewalls')) {
            return;
        }

        /** @var array<string, array{path_prefix: string, login_throttling: bool, two_factor: bool, verify: bool}> $firewalls */
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

            if ($features['two_factor'] && $firewall['two_factor']) {
                $this->assertSecondFactor($container, $name, $firewall['path_prefix']);
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
        $throttling = $this->rawFirewall($container, $firewall)['login_throttling'] ?? null;
        $ignored = \is_array($throttling) ? array_intersect(['max_attempts', 'interval'], array_keys($throttling)) : [];
        if ([] !== $ignored) {
            throw new InvalidConfigurationException(\sprintf('The firewall "%s" sets both "login_throttling.limiter" and "login_throttling.%s": the latter is ignored, remove it and configure the limits in iq2i_herse.login_throttling.', $firewall, implode('", "login_throttling.', $ignored)));
        }
    }

    private function assertSecondFactor(ContainerBuilder $container, string $firewall, string $pathPrefix): void
    {
        $formRoute = HerseRouteLoader::name($firewall, HerseRouteLoader::TWO_FACTOR_FORM);
        $checkRoute = HerseRouteLoader::name($firewall, HerseRouteLoader::TWO_FACTOR_CHECK);
        $formPath = HerseRouteLoader::path($pathPrefix, HerseRouteLoader::TWO_FACTOR_FORM);

        $config = 'security.firewall_config.two_factor.'.$firewall;
        $options = $container->hasDefinition($config) ? $container->getDefinition($config)->getArgument(0) : null;
        $problem = match (true) {
            !\is_array($options) => 'does not ask for the second factor',
            $formRoute !== ($options['auth_form_path'] ?? null), $checkRoute !== ($options['check_path'] ?? null) => 'does not use the second factor routes of Herse',
            true !== ($options['enable_csrf'] ?? null) => 'does not protect the second factor form against CSRF',
            !$container->hasDefinition('security.logout_listener.'.$firewall) => 'has no logout, which the second factor form needs to let the user out',
            default => $this->findPathProblem($container, $firewall, $formPath),
        };

        if (null !== $problem) {
            throw new InvalidConfigurationException(\sprintf(<<<'MESSAGE'
                The firewall "%1$s" %2$s. Its security configuration must contain:

                    security:
                        firewalls:
                            %1$s:
                                logout: { path: ... }
                                two_factor:
                                    auth_form_path: %3$s
                                    check_path: %4$s
                                    enable_csrf: true
                        access_control:
                            # Before the rules that match the same paths.
                            - { path: ^%5$s, roles: %6$s }

                Or set iq2i_herse.firewalls.%1$s.two_factor to false to leave this firewall out.
                MESSAGE, $firewall, $problem, $formRoute, $checkRoute, $formPath, self::IN_PROGRESS));
        }
    }

    /**
     * The second factor form is requested by a user who is not fully authenticated yet: the firewall must cover its
     * path, and the first access rule matching it must let that user in.
     */
    private function findPathProblem(ContainerBuilder $container, string $firewall, string $formPath): ?string
    {
        $pattern = $this->rawFirewall($container, $firewall)['pattern'] ?? null;
        if (\is_string($pattern) && !$this->matches($container, $pattern, $formPath)) {
            return \sprintf('does not cover "%s", the path of the second factor form: check iq2i_herse.firewalls.%s.path_prefix', $formPath, $firewall);
        }

        foreach ($this->rawAccessControl($container) as $rule) {
            if ([] !== array_intersect(self::CONDITIONS, array_keys($rule))) {
                continue;
            }

            $path = $rule['path'] ?? null;
            if (\is_string($path) && !$this->matches($container, $path, $formPath)) {
                continue;
            }

            $roles = $rule['roles'] ?? $rule['role'] ?? [];
            $roles = \is_string($roles) ? array_map(trim(...), explode(',', $roles)) : $roles;

            return \is_array($roles) && \in_array(self::IN_PROGRESS, $roles, true) ? null : \sprintf('has its second factor form "%s" closed by an access rule (%s)', $formPath, \is_string($path) ? 'path: '.$path : 'without a path');
        }

        return null;
    }

    private function matches(ContainerBuilder $container, string $pattern, string $path): bool
    {
        $pattern = $container->getParameterBag()->resolveValue($pattern);

        // As the request matcher of Symfony does.
        return \is_string($pattern) && 1 === @preg_match('{'.$pattern.'}', $path);
    }

    /**
     * The options the project wrote for the firewall, the last file setting one winning as when they are merged.
     *
     * @return array<mixed>
     */
    private function rawFirewall(ContainerBuilder $container, string $firewall): array
    {
        $options = [];
        foreach ($container->getExtensionConfig('security') as $config) {
            $firewalls = \is_array($config['firewalls'] ?? null) ? $config['firewalls'] : [];
            if (\is_array($firewalls[$firewall] ?? null)) {
                $options = array_merge($options, $firewalls[$firewall]);
            }
        }

        return $options;
    }

    /**
     * @return list<array<mixed>> the access rules, which a single configuration file may define
     */
    private function rawAccessControl(ContainerBuilder $container): array
    {
        foreach ($container->getExtensionConfig('security') as $config) {
            if (\is_array($config['access_control'] ?? null)) {
                return array_values(array_filter($config['access_control'], is_array(...)));
            }
        }

        return [];
    }
}
