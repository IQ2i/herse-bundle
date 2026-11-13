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
use IQ2i\HerseBundle\Command\PurgeTrustedDevicesCommand;
use IQ2i\HerseBundle\DependencyInjection\SharedServices;
use IQ2i\HerseBundle\DependencyInjection\Support;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\TrustedDevice\DeviceCookies;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceListener;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceRepository;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TrustedDevice\UserAgentParser;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The registry of the trusted devices and the email warning about a new one.
 *
 * @internal
 */
final class TrustedDevicesFeature
{
    private const DEFAULT_LIFETIME = '30 days';

    public static function configure(ArrayNodeDefinition $node): void
    {
        $children = $node->children();

        $firewall = $children->arrayNode('firewalls')
            ->info('The firewalls whose logins register a device, by firewall name. Every firewall of iq2i_herse.firewalls by default.')
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->arrayPrototype()
            ->treatNullLike([])
            ->addDefaultsIfNotSet()
        ;
        $firewall->children()
            ->scalarNode('cookie_name')
                ->info('Name of the device cookie, "herse_device_<firewall>" by default.')
                ->defaultNull()
                ->cannotBeEmpty()
                ->validate()
                    ->ifTrue(static fn (string $name): bool => 1 !== preg_match('/^[A-Za-z0-9_.-]+$/', $name))
                    ->thenInvalid('%s is not a valid cookie name.')
                ->end()
            ->end()
            ->scalarNode('lifetime')
                ->info('Inactivity after which the device is forgotten, as a relative date format (e.g. "30 days").')
                ->defaultValue(self::DEFAULT_LIFETIME)
                ->cannotBeEmpty()
                ->validate()
                    ->ifTrue(static fn (string $value): bool => !Support::isValidInterval($value))
                    ->thenInvalid('%s is not a valid interval.')
                ->end()
            ->end()
        ;

        $children
            ->booleanNode('store_ip')
                ->info('Keep the last IP address of the device, for display. It is personal data: disable it to store none.')
                ->defaultTrue()
            ->end()
            ->booleanNode('notify_first_device')
                ->info('Warn the user, too, when the device is the first one registered for them (e.g. the first login after the installation).')
                ->defaultTrue()
            ->end()
            ->booleanNode('notify_before_second_factor')
                ->info('With two_factor, warn about a new device as soon as the password is accepted instead of once the second factor is passed. It warns the user that their password is known to a third party even when the second factor then fails.')
                ->defaultFalse()
            ->end()
            ->booleanNode('revoke_on_password_change')
                ->info('Revoke the devices of a user whose password changes, but the current one when the owner changes it. Requires password_change_notification.')
                ->defaultFalse()
            ->end()
        ;
    }

    /**
     * @param array{users: array<class-string, array<string, mixed>>, firewalls: array<string, mixed>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}, trusted_devices: array{firewalls: array<string, array{cookie_name: string|null, lifetime: string}>, store_ip: bool, notify_first_device: bool, notify_before_second_factor: bool, revoke_on_password_change: bool}, password_change_notification: array{enabled: bool}} $config
     */
    public static function load(array $config, ContainerConfigurator $container): void
    {
        $devices = $config['trusted_devices'];
        if ([] === $devices['firewalls']) {
            $devices['firewalls'] = array_fill_keys(array_keys($config['firewalls']), ['cookie_name' => null, 'lifetime' => self::DEFAULT_LIFETIME]);
        }
        if ([] === $devices['firewalls']) {
            throw new InvalidConfigurationException('iq2i_herse.trusted_devices requires at least one firewall, declared in iq2i_herse.firewalls or in iq2i_herse.trusted_devices.firewalls.');
        }
        if ($devices['revoke_on_password_change'] && !$config['password_change_notification']['enabled']) {
            throw new InvalidConfigurationException('iq2i_herse.trusted_devices.revoke_on_password_change requires iq2i_herse.password_change_notification to be enabled.');
        }

        SharedServices::assertEmailRequirements('trusted_devices', [
            'doctrine/orm' => EntityManagerInterface::class,
            'doctrine/doctrine-bundle' => DoctrineBundle::class,
        ]);

        $firewalls = [];
        foreach ($devices['firewalls'] as $name => $firewall) {
            $firewalls[$name] = ['cookie_name' => $firewall['cookie_name'] ?? 'herse_device_'.$name, 'lifetime' => $firewall['lifetime']];
        }

        $services = $container->services();

        $services->set('iq2i_herse.trusted_devices.cookies', DeviceCookies::class)
            ->args([$firewalls])
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onKernelResponse'])
            ->tag('kernel.reset', ['method' => 'reset'])
        ;
        $services->set('iq2i_herse.trusted_devices.repository', TrustedDeviceRepository::class)->args([new Reference('doctrine.orm.entity_manager')]);

        $services->set('iq2i_herse.trusted_devices.listener', TrustedDeviceListener::class)
            ->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.trusted_devices.repository'),
                new Reference('iq2i_herse.trusted_devices.cookies'),
                new Reference('iq2i_herse.trusted_devices.user_agent_parser'),
                new Reference('iq2i_herse.mailer'),
                new Reference('event_dispatcher'),
                new Reference('logger'),
                $devices['store_ip'],
                $devices['notify_first_device'],
                $devices['notify_before_second_factor'],
            ])
            // Before the trusted device listener of scheb: the device of a finished login must exist when scheb verifies it.
            ->tag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'priority' => 16])
        ;
        $services->set('iq2i_herse.trusted_devices.user_agent_parser', UserAgentParser::class);

        // Public: the projects list and revoke the devices through it.
        $services->set(TrustedDevices::class)
            ->args([
                new Reference('iq2i_herse.user_resolver'),
                new Reference('iq2i_herse.trusted_devices.repository'),
                new Reference('iq2i_herse.trusted_devices.cookies'),
                new Reference('request_stack'),
            ])
            ->public()
        ;

        if (class_exists(Command::class)) {
            $services->set(PurgeTrustedDevicesCommand::class)->args([new Reference('iq2i_herse.trusted_devices.repository')])->tag('console.command');
        }

        SharedServices::loadMailer($config, $container);
    }

    /**
     * Maps the device entity of the bundle without a line in the Doctrine config of the project.
     *
     * @param array<array<string, mixed>> $configs the raw configurations of the bundle
     */
    public static function prepend(ContainerBuilder $builder, array $configs): void
    {
        if (!$builder->hasExtension('doctrine') || true !== Support::rawFeature($configs, 'trusted_devices')['enabled']) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['IQ2iHerse' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => \dirname(__DIR__, 2).'/Entity',
            'prefix' => 'IQ2i\\HerseBundle\\Entity',
        ]]]]);
    }
}
