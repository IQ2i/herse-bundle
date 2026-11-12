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

use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The application shared by the environments needing Doctrine, Twig, the mailer and the Account entity.
 */
final class AccountApplication
{
    /**
     * @param array<string, mixed>                $herse     options of iq2i_herse, besides the users and the mailer
     * @param array<string, array<string, mixed>> $firewalls
     */
    public static function load(ContainerConfigurator $container, array $herse, array $firewalls, string $passwordField = 'password'): void
    {
        $container->extension('iq2i_herse', [
            'users' => [
                Account::class => ['type' => 'customer', 'email' => 'email', 'locale' => 'locale', 'password' => $passwordField],
            ],
            'mailer' => ['sender' => 'no-reply@example.com', 'sender_name' => 'Example', 'app_name' => 'Example shop'],
        ] + $herse);
        $container->extension('framework', [
            'default_locale' => 'en',
            'translator' => ['enabled' => true],
            'mailer' => ['dsn' => 'null://null'],
        ]);
        $container->extension('twig', []);
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => ['mappings' => ['Test' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => '%kernel.project_dir%/src/Entity',
                'prefix' => 'IQ2i\HerseBundle\Tests\TestApplication\Entity',
            ]]],
        ]);
        $container->extension('security', [
            // Cost 5 while the stored hashes of some tests use cost 4: such a hash needs a rehash at login.
            'password_hashers' => [Account::class => ['algorithm' => 'bcrypt', 'cost' => 5]],
            'providers' => ['accounts' => ['entity' => ['class' => Account::class, 'property' => 'email']]],
            'firewalls' => $firewalls,
        ]);

        $services = $container->services();
        $services->set(AccountRepository::class)->autowire()->autoconfigure();
        $services->set(ResetPasswordController::class)->autowire()->public();
        $services->set('test.logger', InMemoryLogger::class)->decorate('logger');
    }
}
