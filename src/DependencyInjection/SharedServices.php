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
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The services shared by several features: the emails and the user resolver.
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
