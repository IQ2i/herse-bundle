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

namespace IQ2i\HerseBundle\Tests\TestApplication;

use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The "admin" and "main" firewalls ask for a second factor with the routes and the screens of the bundle. "/private"
 * and "/admin/private" require a complete login.
 */
final class TwoFactorScreensConfig
{
    /**
     * @param 'email'|'totp'       $method
     * @param class-string         $accountClass the user class
     * @param array<string, mixed> $herse        more options of iq2i_herse
     * @param array<string, mixed> $twoFactor    more options of two_factor
     */
    public static function load(ContainerConfigurator $container, string $method, string $accountClass, array $herse = [], array $twoFactor = []): void
    {
        $firewalls = [];
        foreach (['admin' => '/admin', 'main' => ''] as $name => $prefix) {
            $firewalls[$name] = [
                'provider' => 'accounts',
                'json_login' => ['check_path' => $prefix.'/login'],
                'logout' => ['path' => $prefix.'/logout'],
                'two_factor' => [
                    'auth_form_path' => HerseRouteLoader::name($name, HerseRouteLoader::TWO_FACTOR_FORM),
                    'check_path' => HerseRouteLoader::name($name, HerseRouteLoader::TWO_FACTOR_CHECK),
                    'enable_csrf' => true,
                    'prepare_on_login' => true,
                ],
            ];
        }

        AccountApplication::load($container, $herse + [
            'firewalls' => ['admin' => ['path_prefix' => '/admin', 'layout' => 'admin_layout.html.twig'], 'main' => null],
            'two_factor' => $twoFactor + ['method' => $method, 'throttling' => ['cache_pool' => 'herse_two_factor_throttling']],
        ], $firewalls, accountClass: $accountClass);

        $container->extension('framework', [
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            // Not an array adapter: the kernel resets it between two requests, which would clear the counters.
            'cache' => ['pools' => ['herse_two_factor_throttling' => ['adapter' => 'cache.adapter.filesystem']]],
        ]);
        $container->services()->alias('test.two_factor_throttling.cache', 'herse_two_factor_throttling')->public();
        $container->services()->alias('test.event_dispatcher', 'event_dispatcher')->public();
        $container->extension('security', ['access_control' => [
            ['path' => '^/admin/2fa', 'roles' => 'IS_AUTHENTICATED_2FA_IN_PROGRESS'],
            ['path' => '^/2fa', 'roles' => 'IS_AUTHENTICATED_2FA_IN_PROGRESS'],
            ['path' => '^/(admin/)?private', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ]]);
    }
}
