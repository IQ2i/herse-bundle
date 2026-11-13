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

namespace IQ2i\HerseBundle\Tests\Routing;

use IQ2i\HerseBundle\Controller\TotpEnrollmentController;
use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use PHPUnit\Framework\TestCase;

final class HerseRouteLoaderTest extends TestCase
{
    private const FIREWALLS = [
        'admin' => ['path_prefix' => '/admin', 'two_factor' => true],
        'main' => ['path_prefix' => '', 'two_factor' => true],
        'api' => ['path_prefix' => '/api', 'two_factor' => false],
    ];

    public function testEachFirewallAskingTheSecondFactorGetsItsRoutes(): void
    {
        $routes = (new HerseRouteLoader(self::FIREWALLS, 'email'))->load('.', 'iq2i_herse');

        self::assertSame(
            ['iq2i_herse_admin_two_factor_form' => '/admin/2fa', 'iq2i_herse_admin_two_factor_check' => '/admin/2fa_check', 'iq2i_herse_main_two_factor_form' => '/2fa', 'iq2i_herse_main_two_factor_check' => '/2fa_check'],
            array_map(static fn ($route): string => $route->getPath(), $routes->all()),
        );
        self::assertSame('scheb_two_factor.form_controller::form', $routes->get('iq2i_herse_main_two_factor_form')?->getDefault('_controller'));
        self::assertSame(['GET'], $routes->get('iq2i_herse_main_two_factor_form')->getMethods());
        self::assertSame(['POST'], $routes->get('iq2i_herse_main_two_factor_check')?->getMethods());
        self::assertNull($routes->get('iq2i_herse_main_two_factor_check')->getDefault('_controller'));
    }

    public function testTheEnrollmentScreenComesWithTheAuthenticatorApp(): void
    {
        $routes = (new HerseRouteLoader(self::FIREWALLS, 'totp'))->load('.', 'iq2i_herse');

        self::assertSame('/admin/second-factor/setup', $routes->get('iq2i_herse_admin_two_factor_setup')?->getPath());
        self::assertSame(TotpEnrollmentController::class, $routes->get('iq2i_herse_main_two_factor_setup')?->getDefault('_controller'));
        self::assertSame(['GET', 'POST'], $routes->get('iq2i_herse_main_two_factor_setup')->getMethods());
        self::assertNull($routes->get('iq2i_herse_api_two_factor_setup'));
    }

    public function testNoRouteIsGeneratedWhileTheSecondFactorIsDisabled(): void
    {
        self::assertCount(0, (new HerseRouteLoader(self::FIREWALLS, null))->load('.', 'iq2i_herse'));
    }

    public function testOnlyItsOwnResourceTypeIsLoaded(): void
    {
        $loader = new HerseRouteLoader(self::FIREWALLS, 'email');

        self::assertTrue($loader->supports('.', 'iq2i_herse'));
        self::assertFalse($loader->supports('.', 'attribute'));
        self::assertFalse($loader->supports('.'));
    }
}
