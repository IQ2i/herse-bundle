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

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Three firewalls on the same accounts: "main" and "admin" are watched, "public" is not.
 */
final class TrustedDevicesConfig
{
    /**
     * @param array<string, mixed> $devices options of trusted_devices, besides the firewalls
     */
    public static function load(ContainerConfigurator $container, array $devices = [], bool $revokeOnPasswordChange = false): void
    {
        $login = static fn (string $path): array => ['stateless' => true, 'provider' => 'accounts', 'json_login' => ['check_path' => $path]];

        $herse = ['trusted_devices' => ['enabled' => true, 'firewalls' => ['main' => null, 'admin' => ['cookie_name' => 'admin_device', 'lifetime' => '7 days']]] + $devices];
        if ($revokeOnPasswordChange) {
            $herse['password_change_notification'] = ['enabled' => true];
            $herse['trusted_devices']['revoke_on_password_change'] = true;
        }

        AccountApplication::load($container, $herse, [
            'admin' => ['pattern' => '^/admin'] + $login('/admin/login'),
            'public' => ['pattern' => '^/public'] + $login('/public/login'),
            'main' => $login('/login'),
        ]);
    }
}
