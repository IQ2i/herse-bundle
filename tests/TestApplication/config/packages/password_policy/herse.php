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

use IQ2i\HerseBundle\Tests\TestApplication\User;
use Symfony\Component\Form\Form;
use Symfony\Component\Security\Core\User\InMemoryUser;

return static function (ContainerConfigurator $container): void {
    $container->extension('iq2i_herse', [
        'users' => [
            User::class => ['type' => 'shop', 'email' => 'email', 'plain_password' => 'plainPassword'],
        ],
        'password_policy' => true,
    ]);
    $container->extension('framework', [
        'default_locale' => 'fr',
        'translator' => ['enabled' => true],
        'form' => ['enabled' => class_exists(Form::class)],
    ]);
    $container->extension('security', [
        'password_hashers' => [InMemoryUser::class => ['algorithm' => 'bcrypt', 'cost' => 4]],
    ]);
    // Private services are removed when unused; the tests read these through public aliases.
    $services = $container->services();
    $services->alias('test.validator', 'validator')->public();
    $services->alias('test.password_hasher', 'security.user_password_hasher')->public();
    if (class_exists(Form::class)) {
        $services->alias('test.form.factory', 'form.factory')->public();
    }
};
