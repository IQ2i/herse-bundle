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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class FirewallRegistryTest extends TestCase
{
    public function testThereIsNoCurrentFirewallOutsideOfARequest(): void
    {
        $registry = FirewallRegistries::create(['main' => ['layout' => 'layout.html.twig']], null);

        self::assertNull($registry->current());
        self::assertNull($registry->layout());
    }

    public function testTheFirewallOfTheRequestIsTheCurrentOneWhenItIsDeclared(): void
    {
        self::assertSame('admin', FirewallRegistries::create(['admin' => []], 'admin')->current());
        self::assertNull(FirewallRegistries::create(['admin' => []], 'api')->current());
    }

    public function testAFirewallHasItsOwnLayoutOrNone(): void
    {
        self::assertSame('admin.html.twig', FirewallRegistries::create(['admin' => ['layout' => 'admin.html.twig']], 'admin')->layout());
        self::assertNull(FirewallRegistries::create(['admin' => []], 'admin')->layout());
    }

    public function testAFirewallMayHaveAThemeReplacingTheTemplatesOfTheBundle(): void
    {
        self::assertSame('@Sylius/admin', FirewallRegistries::create(['admin' => ['theme' => '@Sylius/admin']], 'admin')->theme());
        self::assertNull(FirewallRegistries::create(['admin' => []], 'admin')->theme());
        self::assertNull(FirewallRegistries::create(['admin' => ['theme' => '@Sylius/admin']], null)->theme());
    }

    public function testTheSecondFactorIsAskedOnTheFirewallsThatDidNotOptOut(): void
    {
        $firewalls = ['admin' => [], 'api' => ['two_factor' => false]];

        self::assertTrue(FirewallRegistries::create($firewalls, null)->asksSecondFactor('admin'));
        self::assertFalse(FirewallRegistries::create($firewalls, null)->asksSecondFactor('api'));
        self::assertFalse(FirewallRegistries::create($firewalls, null)->asksSecondFactor('unknown'));
        self::assertFalse(FirewallRegistries::create($firewalls, null, secondFactorRequired: false)->asksSecondFactor('admin'));
    }

    public function testTheHomeOfAFirewallIsItsPrefixAPathOrARoute(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard', new Route('/admin/dashboard'));
        $registry = FirewallRegistries::create([
            'main' => [],
            'admin' => ['path_prefix' => '/admin'],
            'shop' => ['home' => '/account'],
            'back' => ['home' => 'dashboard'],
        ], null, urls: new UrlGenerator($routes, new RequestContext()));

        self::assertSame('/', $registry->home('main'));
        self::assertSame('/admin', $registry->home('admin'));
        self::assertSame('/account', $registry->home('shop'));
        self::assertSame('/admin/dashboard', $registry->home('back'));
    }
}
