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

namespace IQ2i\HerseBundle\Tests\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use IQ2i\HerseBundle\Bridge\EasyAdmin\ResetTwoFactorAction;
use IQ2i\HerseBundle\Tests\TwoFactor\TwoFactorResetLinkTest;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class ResetTwoFactorActionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Action::class) || !class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('easycorp/easyadmin-bundle and scheb/2fa-bundle are not installed.');
        }
    }

    public function testTheActionLinksToTheConfirmationScreenOfTheUser(): void
    {
        $action = (new ResetTwoFactorAction(TwoFactorResetLinkTest::link()))->create()->getAsDto();
        $url = $action->getUrl();

        self::assertSame(ResetTwoFactorAction::NAME, $action->getName());
        self::assertSame('Reset two-factor', $action->getLabel());
        self::assertIsCallable($url);
        $link = $url(TwoFactorResetLinkTest::account());
        self::assertIsString($link);
        self::assertStringStartsWith('/admin/second-factor/reset/customer/7?back=', $link);
        self::assertSame('#', $url(TwoFactorResetLinkTest::account(secret: null)));
    }

    public function testTheLabelIsTranslated(): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['Reset two-factor' => 'Réinitialiser la 2FA'], 'fr', 'iq2i_herse');

        self::assertSame('Réinitialiser la 2FA', (new ResetTwoFactorAction(TwoFactorResetLinkTest::link(), $translator))->create()->getAsDto()->getLabel());
    }
}
