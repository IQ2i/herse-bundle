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

namespace IQ2i\HerseBundle\DependencyInjection\Feature;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use IQ2i\HerseBundle\DependencyInjection\SharedServices;
use IQ2i\HerseBundle\DependencyInjection\Support;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\PasswordChange\AuthenticationWindow;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeListener;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The detection of the password changes and the email warning the account owner.
 *
 * @internal
 */
final class PasswordChangeNotificationFeature
{
    public static function configure(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->booleanNode('notify_on_other_user')
                    ->info('Warn the account owner when another authenticated user (an administrator) changes the password.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('notify_on_console')
                    ->info('Warn the account owner when the password is changed outside of a web request (console command, worker).')
                    ->defaultTrue()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array{users: array<class-string, array{password: string}>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, password_change_notification: array{notify_on_other_user: bool, notify_on_console: bool}, trusted_devices: array{enabled: bool, revoke_on_password_change: bool}} $config
     */
    public static function load(array $config, ContainerConfigurator $container): void
    {
        SharedServices::assertEmailRequirements('password_change_notification', [
            'doctrine/orm' => EntityManagerInterface::class,
            'doctrine/doctrine-bundle' => DoctrineBundle::class,
        ]);

        foreach ($config['users'] as $class => $user) {
            if (!Support::hasProperty($class, $user['password'])) {
                throw new InvalidConfigurationException(\sprintf('iq2i_herse.users: class "%s" has no "%s" property.', $class, $user['password']));
            }
        }

        $notification = $config['password_change_notification'];
        $services = $container->services();

        $services->set('iq2i_herse.password_change.authentication_window', AuthenticationWindow::class)
            ->tag('kernel.event_listener', ['event' => CheckPassportEvent::class, 'method' => 'open', 'priority' => 2048])
            ->tag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'close', 'priority' => -2048])
            ->tag('kernel.event_listener', ['event' => LoginFailureEvent::class, 'method' => 'close', 'priority' => -2048])
            ->tag('kernel.reset', ['method' => 'reset'])
        ;

        // Public: projects switch the notification off around fixtures or imports.
        $services->set(PasswordChangeNotification::class)->public()->tag('kernel.reset', ['method' => 'reset']);

        $services->set('iq2i_herse.password_change.listener', PasswordChangeListener::class)
            ->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.password_change.authentication_window'),
                new Reference(PasswordChangeNotification::class),
                new Reference('iq2i_herse.mailer'),
                new Reference('event_dispatcher'),
                new Reference('request_stack'),
                new Reference('security.token_storage'),
                new Reference('logger'),
                $notification['notify_on_other_user'],
                $notification['notify_on_console'],
                $config['trusted_devices']['enabled'] && $config['trusted_devices']['revoke_on_password_change'] ? new Reference(TrustedDevices::class) : null,
            ])
            ->tag('doctrine.event_listener', ['event' => 'onFlush', 'lazy' => true])
            ->tag('doctrine.event_listener', ['event' => 'postFlush', 'lazy' => true])
            ->tag('doctrine.event_listener', ['event' => 'onClear', 'lazy' => true])
        ;

        SharedServices::loadMailer($config, $container);
    }
}
