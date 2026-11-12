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
 * The configuration shared by the environments testing the password change notification.
 */
final class PasswordChangeConfig
{
    /**
     * @param array<string, bool> $notification options of password_change_notification
     */
    public static function load(ContainerConfigurator $container, array $notification = [], string $passwordField = 'password'): void
    {
        AccountApplication::load(
            $container,
            ['password_change_notification' => ['enabled' => true] + $notification],
            ['main' => ['stateless' => true, 'provider' => 'accounts', 'json_login' => ['check_path' => '/login']]],
            $passwordField,
        );
    }
}
