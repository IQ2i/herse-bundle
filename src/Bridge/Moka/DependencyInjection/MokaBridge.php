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

namespace IQ2i\HerseBundle\Bridge\Moka\DependencyInjection;

use IQ2i\HerseBundle\Bridge\Moka\Controller\DeviceController;
use IQ2i\HerseBundle\Bridge\Moka\EventListener\MenuListener;
use IQ2i\HerseBundle\Bridge\Moka\Form\ResetPasswordTypeExtension;
use IQ2i\HerseBundle\Bridge\Moka\Form\UserTypeExtension;
use IQ2i\HerseBundle\DependencyInjection\Support;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wires the features on a MokaCMS project: what such a project would otherwise write by hand.
 *
 * The classes of Moka are named by strings here: MokaCMS is a private package, never installed with the bundle.
 *
 * @internal
 */
final class MokaBridge
{
    public const BUNDLE = 'Moka\Cms\MokaCmsBundle';
    public const USER_CLASS = 'Moka\Cms\Entity\User\AbstractUser';

    public static function configure(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Integration with MokaCMS: declares its user, applies the password policy to its reset screen, adds a device screen to its administration and the email second factor to its generated user class.')
            ->children()
                ->scalarNode('firewall')
                    ->info('Name of the firewall of the Moka administration.')
                    ->defaultValue('moka')
                    ->cannotBeEmpty()
                ->end()
            ->end()
        ;
    }

    /**
     * The values come first: the configuration of the project overrides them.
     *
     * @param array<array<string, mixed>> $configs
     */
    public static function prepend(ContainerBuilder $builder, array $configs): void
    {
        $moka = Support::rawFeature($configs, 'moka');
        if (!$moka['enabled'] || !class_exists(self::USER_CLASS)) {
            return;
        }

        // The user class of Moka is generated when the cache is warmed up, after the container is compiled: its
        // parent class is declared, the users are matched with "instanceof".
        $defaults = ['users' => [self::USER_CLASS => ['type' => 'admin', 'email' => 'email', 'locale' => 'locale']]];

        $mailer = [];
        foreach ($builder->getExtensionConfig('moka_cms') as $config) {
            $mailer = array_filter(['sender' => $config['email_sender'] ?? null, 'app_name' => $config['site_name'] ?? null]) + $mailer;
        }
        if ([] !== $mailer) {
            $defaults['mailer'] = $mailer;
        }

        $firewall = \is_string($moka['firewall'] ?? null) ? $moka['firewall'] : 'moka';
        $devices = Support::rawFeature($configs, 'trusted_devices');
        if ($devices['enabled'] && !isset($devices['firewalls'])) {
            $defaults['trusted_devices'] = ['firewalls' => [$firewall => null]];
        }

        $builder->prependExtensionConfig('iq2i_herse', $defaults);

        $twoFactor = Support::rawFeature($configs, 'two_factor');
        if ($twoFactor['enabled'] && 'email' === ($twoFactor['method'] ?? null) && $builder->hasExtension('scheb_two_factor')) {
            $builder->prependExtensionConfig('scheb_two_factor', ['email' => ['template' => '@IQ2iHerse/moka/two_factor.html.twig']]);
        }
    }

    /**
     * @param array{moka: array{firewall: string}, password_policy: array{enabled: bool}, trusted_devices: array{enabled: bool}, two_factor: array{enabled: bool, method?: 'email'|'totp'}} $config
     */
    public static function load(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        if (!\is_array($bundles) || !\in_array(self::BUNDLE, $bundles, true)) {
            throw new InvalidConfigurationException(\sprintf('iq2i_herse.moka requires the bundle "%s" to be registered in config/bundles.php.', self::BUNDLE));
        }

        $services = $container->services();

        if ($config['password_policy']['enabled']) {
            $services->set('iq2i_herse.moka.reset_password_type_extension', ResetPasswordTypeExtension::class)->tag('form.type_extension');
        }

        if ($config['trusted_devices']['enabled']) {
            $services->set(DeviceController::class)
                ->args([
                    new Reference(TrustedDevices::class),
                    new Reference('security.token_storage'),
                    new Reference('security.csrf.token_manager'),
                    new Reference('router'),
                    new Reference('twig'),
                    $config['moka']['firewall'],
                ])
                ->public()
            ;
            $services->set('iq2i_herse.moka.menu_listener', MenuListener::class)
                ->args([new Reference(TranslatorInterface::class)])
                ->tag('kernel.event_listener', ['event' => 'moka_cms.menu_configure'])
            ;
        }

        // The authenticator app needs an enrolment screen the bridge does not have: only the email is wired.
        if ($config['two_factor']['enabled'] && 'email' === ($config['two_factor']['method'] ?? null)) {
            $builder->setParameter(MokaUserClassPass::PARAMETER, true);
            $services->set('iq2i_herse.moka.user_type_extension', UserTypeExtension::class)
                ->args([new Reference('Moka\Cms\Security\RoleManager')])
                ->tag('form.type_extension')
            ;
        }
    }
}
