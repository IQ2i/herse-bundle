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

namespace IQ2i\HerseBundle\Tests\Twig;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use IQ2i\HerseBundle\Tests\Security\FirewallRegistries;
use IQ2i\HerseBundle\Twig\HerseExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class HerseExtensionTest extends TestCase
{
    public function testThePasswordPolicyIsListedInATemplate(): void
    {
        $twig = new Environment(new ArrayLoader(['help' => '{% for rule in herse_password_policy() %}[{{ rule }}]{% endfor %}']));
        $twig->addExtension(new HerseExtension(new PasswordPolicyDescriber(10, 0), null, 'layout.html.twig'));

        self::assertSame('[at least 10 characters]', $twig->render('help'));
    }

    public function testThePasswordPolicyIsNotAvailableWhileTheFeatureIsDisabled(): void
    {
        $this->expectExceptionMessage('herse_password_policy() requires iq2i_herse.password_policy to be enabled.');

        (new HerseExtension(null, null, 'layout.html.twig'))->passwordPolicy();
    }

    public function testTheTemplatesOfTheThemeOfTheFirewallComeFirst(): void
    {
        $themed = new HerseExtension(null, FirewallRegistries::create(['admin' => ['theme' => '@App/herse']], 'admin'), 'layout.html.twig');
        $plain = new HerseExtension(null, FirewallRegistries::create(['admin' => []], 'admin'), 'layout.html.twig');

        self::assertSame(['@App/herse/security.html.twig', '@IQ2iHerse/frame.html.twig'], $themed->frame('security'));
        self::assertSame(['@App/herse/devices/_list.html.twig', '@IQ2iHerse/devices/_list.html.twig'], $themed->template('devices/_list.html.twig'));
        self::assertSame(['@IQ2iHerse/frame.html.twig'], $plain->frame());
        self::assertSame(['@IQ2iHerse/devices/_list.html.twig'], $plain->template('devices/_list.html.twig'));
    }

    public function testTheScreensExtendTheConfiguredLayoutWithoutDeclaredFirewall(): void
    {
        $twig = new Environment(new ArrayLoader([
            'layout.html.twig' => '<title>{% block title %}{% endblock %}</title><main>{% block body %}{% endblock %}</main>',
            '@IQ2iHerse/frame.html.twig' => (string) file_get_contents(\dirname(__DIR__, 2).'/templates/frame.html.twig'),
            'page' => "{% extends herse_layout('security') %}{% block herse_title %}title{% endblock %}{% block herse_content %}page{% endblock %}",
        ]));
        $twig->addExtension(new HerseExtension(null, null, 'layout.html.twig'));

        self::assertSame('<title>title</title><main>page</main>', $twig->render('page'));
    }
}
