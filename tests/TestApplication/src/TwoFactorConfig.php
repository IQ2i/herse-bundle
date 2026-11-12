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

use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TwoFactorAccount;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The "main" firewall asks for a second factor after the JSON login, and keeps its session. "/private" requires a complete login.
 */
final class TwoFactorConfig
{
    /**
     * @param array<string, mixed> $twoFactor        options of two_factor
     * @param array<string, mixed> $devices          options of trusted_devices, besides the "main" firewall
     * @param bool                 $withDevices      whether the trusted devices are enabled
     * @param bool                 $firewallAsksCode whether the firewall declares two_factor
     * @param array<string, mixed> $schebConfig      the scheb_two_factor configuration of the project
     * @param class-string         $accountClass     the user class
     */
    public static function load(ContainerConfigurator $container, array $twoFactor = ['method' => 'email'], array $devices = [], bool $withDevices = true, bool $firewallAsksCode = true, array $schebConfig = [], string $accountClass = TwoFactorAccount::class): void
    {
        // scheb builds the logout URL of the second factor form.
        $main = ['provider' => 'accounts', 'json_login' => ['check_path' => '/login'], 'logout' => ['path' => '/logout']];
        if ($firewallAsksCode) {
            $main['two_factor'] = ['auth_form_path' => '/2fa', 'check_path' => '/2fa_check', 'prepare_on_login' => true];
        }

        $herse = ['two_factor' => $twoFactor + ['enabled' => true]];
        if ($withDevices) {
            $herse['trusted_devices'] = ['enabled' => true, 'firewalls' => ['main' => null]] + $devices;
        }

        AccountApplication::load($container, $herse, ['main' => $main], accountClass: $accountClass);

        $container->extension('framework', ['session' => ['storage_factory_id' => 'session.storage.factory.mock_file']]);
        $container->extension('security', ['access_control' => [
            ['path' => '^/2fa', 'roles' => 'IS_AUTHENTICATED_2FA_IN_PROGRESS'],
            ['path' => '^/private', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ]]);

        if ([] !== $schebConfig) {
            $container->extension('scheb_two_factor', $schebConfig);
        }
    }
}
