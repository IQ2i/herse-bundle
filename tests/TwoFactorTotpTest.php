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

namespace IQ2i\HerseBundle\Tests;

use OTPHP\TOTP;
use Scheb\TwoFactorBundle\Security\Http\EventListener\CheckTwoFactorCodeReuseListener;

final class TwoFactorTotpTest extends TwoFactorKernelTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected static function environment(): string
    {
        return 'two_factor_totp';
    }

    public function testTheCodeOfTheAuthenticatorAppOpensTheSession(): void
    {
        $this->twoFactorAccount(emailAuth: false, totpSecret: self::SECRET);

        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
        self::assertSame([], $this->mails(), 'No email is sent with TOTP.');
        $this->submitCode(TOTP::createFromSecret(self::SECRET)->now());
        $this->assertLoggedIn();
        self::assertCount(1, $this->twoFactorEvents);
    }

    public function testAWrongCodeIsRefused(): void
    {
        $this->twoFactorAccount(emailAuth: false, totpSecret: self::SECRET);
        $this->signIn();

        $this->assertSecondFactorAsked($this->submitCode('123456'));

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testACodeThatOpenedASessionIsRefusedTheSecondTime(): void
    {
        if (!class_exists(CheckTwoFactorCodeReuseListener::class)) {
            self::markTestSkipped('scheb/2fa-bundle remembers the accepted codes from 7.10 only.');
        }

        $this->twoFactorAccount(emailAuth: false, totpSecret: self::SECRET);
        $code = TOTP::createFromSecret(self::SECRET)->now();
        $this->signIn();
        $this->submitCode($code);
        $this->assertLoggedIn();

        $this->closeSession();
        $this->signIn();

        $this->assertSecondFactorAsked($this->submitCode($code));
        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testTheIssuerDefaultsToTheNameOfTheApplication(): void
    {
        self::assertSame('Example shop', $this->kernel->getContainer()->getParameter('scheb_two_factor.totp.issuer'));
    }
}
