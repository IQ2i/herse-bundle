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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Security\Core\User\InMemoryUser;

return static function (ContainerConfigurator $container): void {
    $container->extension('iq2i_herse', [
        'login_throttling' => [
            'identifier_and_ip' => ['limit' => 3],
            'ip' => ['limit' => 6],
            'identifier' => ['limit' => 4],
            'cache_pool' => 'herse_login_throttling',
        ],
    ]);
    $container->extension('framework', [
        // Not an array adapter: the kernel resets it between two requests, which would clear the counters.
        'cache' => ['pools' => ['herse_login_throttling' => ['adapter' => 'cache.adapter.filesystem']]],
    ]);
    $container->extension('security', [
        'password_hashers' => [InMemoryUser::class => ['algorithm' => 'bcrypt', 'cost' => 4]],
        'providers' => ['users' => ['memory' => ['users' => [
            'alice' => ['password' => password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 4]), 'roles' => ['ROLE_USER']],
        ]]]],
        'firewalls' => ['main' => [
            'stateless' => true,
            'json_login' => ['check_path' => '/login'],
            'login_throttling' => ['limiter' => 'iq2i_herse.login_throttling.limiter'],
        ]],
    ]);
    $container->services()
        ->alias('test.event_dispatcher', 'event_dispatcher')->public()
        ->alias('test.login_throttling.cache', 'herse_login_throttling')->public()
    ;
};
