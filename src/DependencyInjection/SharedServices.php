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

namespace IQ2i\HerseBundle\DependencyInjection;

use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use IQ2i\HerseBundle\Security\FirewallRegistry;
use IQ2i\HerseBundle\Twig\HerseExtension;
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;

/**
 * The services shared by several features: the emails, the user resolver and the screens.
 *
 * @internal
 */
final class SharedServices
{
    /**
     * The services shared by the features sending emails.
     *
     * @param array{users: array<class-string, array<string, mixed>>, mailer: array{sender: string|null, sender_name: string|null, app_name: string|null}} $config
     */
    public static function loadMailer(array $config, ContainerConfigurator $container): void
    {
        if (null === $config['mailer']['sender']) {
            throw new InvalidConfigurationException('iq2i_herse.mailer.sender is required when a feature sending emails is enabled.');
        }

        self::loadUserResolver($config, $container);
        $services = $container->services();
        $services->set('iq2i_herse.mailer', Mailer::class)->args([
            new Reference(MailerInterface::class),
            new Reference(TranslatorInterface::class),
            new Reference('logger'),
            $config['mailer']['sender'],
            $config['mailer']['sender_name'],
            $config['mailer']['app_name'],
        ]);
    }

    /**
     * @param array{users: array<class-string, array<string, mixed>>} $config
     */
    public static function loadUserResolver(array $config, ContainerConfigurator $container): void
    {
        $container->services()->set('iq2i_herse.user_resolver', UserResolver::class)->args([$config['users'], new Reference('property_accessor')]);
    }

    /**
     * The routes, the layout and the Twig functions of the screens of the bundle.
     *
     * @param array{firewalls: array<string, array{path_prefix: string, home: string|null, layout: string|null, two_factor: bool}>, ui: array{layout: string}, password_policy: array{enabled: bool}, two_factor: array{enabled: bool, method?: 'email'|'totp', required: bool, reset_role: string|null}} $config
     */
    public static function loadUi(array $config, ContainerConfigurator $container): void
    {
        $services = $container->services();

        if ([] !== $config['firewalls']) {
            $services->set('iq2i_herse.firewall_registry', FirewallRegistry::class)->args([
                $config['firewalls'],
                new Reference('security.helper'),
                new Reference('request_stack'),
                new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $config['two_factor']['enabled'] && $config['two_factor']['required'],
            ]);

            if (class_exists(RouteCollection::class)) {
                $services->set('iq2i_herse.route_loader', HerseRouteLoader::class)
                    ->args([$config['firewalls'], $config['two_factor']['enabled'] ? $config['two_factor']['method'] ?? null : null, null !== $config['two_factor']['reset_role']])
                    ->tag('routing.loader')
                ;
            }
        }

        if (class_exists(AbstractExtension::class) && ($config['password_policy']['enabled'] || $config['two_factor']['enabled'])) {
            $services->set(HerseExtension::class)
                ->args([
                    $config['password_policy']['enabled'] ? new Reference(PasswordPolicyDescriber::class) : null,
                    [] !== $config['firewalls'] ? new Reference('iq2i_herse.firewall_registry') : null,
                    $config['ui']['layout'],
                ])
                ->tag('twig.extension')
            ;
        }
    }

    /**
     * @param array<string, class-string> $extraPackages package => class, checked with the packages every email needs
     */
    public static function assertEmailRequirements(string $feature, array $extraPackages): void
    {
        $missing = [];
        foreach ([
            'symfony/mailer' => MailerInterface::class,
            'symfony/twig-bundle' => TwigBundle::class,
            'symfony/translation' => Translator::class,
        ] + $extraPackages as $package => $class) {
            if (!ContainerBuilder::willBeAvailable($package, $class, ['iq2i/herse-bundle'])) {
                $missing[] = $package;
            }
        }

        if ([] !== $missing) {
            throw new InvalidConfigurationException(\sprintf('iq2i_herse.%s requires %s. Try running "composer require %s".', $feature, implode(', ', $missing), implode(' ', $missing)));
        }
    }
}
