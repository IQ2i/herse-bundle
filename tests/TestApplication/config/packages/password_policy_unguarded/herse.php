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
        'password_policy' => ['hash_guard' => false],
    ]);
    $container->extension('security', [
        'password_hashers' => [InMemoryUser::class => ['algorithm' => 'bcrypt', 'cost' => 4]],
    ]);
    $container->services()->alias('test.password_hasher', 'security.user_password_hasher')->public();
};
