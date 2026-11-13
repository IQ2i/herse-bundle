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

namespace IQ2i\HerseBundle\Tests\Security;

use IQ2i\HerseBundle\Security\FirewallRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds a registry whose current request goes through a given firewall, without booting a kernel.
 */
final class FirewallRegistries
{
    /**
     * @param array<string, array<string, mixed>> $firewalls the declared firewalls, completed with the defaults
     * @param string|null                         $current   the firewall of the current request, null outside of a request
     */
    public static function create(array $firewalls, ?string $current, bool $secondFactorRequired = true, ?UrlGeneratorInterface $urls = null): FirewallRegistry
    {
        $container = new Container();
        $requests = new RequestStack();
        if (null !== $current) {
            $container->set('context', new FirewallContext([], null, null, new FirewallConfig($current, 'security.user_checker')));
            $requests->push(Request::create('/'));
        }
        $container->set('security.firewall.map', new FirewallMap($container, ['context' => null]));

        /** @var array<string, array{path_prefix: string, home: string|null, layout: string|null, two_factor: bool, theme?: string|null}> $declared */
        $declared = array_map(static fn (array $firewall): array => $firewall + ['path_prefix' => '', 'home' => null, 'layout' => null, 'two_factor' => true], $firewalls);

        return new FirewallRegistry(
            $declared,
            new Security($container),
            $requests,
            $urls,
            $secondFactorRequired,
        );
    }
}
