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

use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use OTPHP\TOTP;

final class TwoFactorScreensTotpTest extends TwoFactorKernelTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected static function environment(): string
    {
        return 'two_factor_screens_totp';
    }

    public function testTheCodeOfTheAuthenticatorAppOpensTheSession(): void
    {
        $account = new TotpAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $account->setTotpSecret(self::SECRET);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $this->signIn();
        $html = (string) $this->send('GET', '/2fa')->getContent();
        self::assertStringContainsString('Enter the code shown by your authenticator app.', $html);
        $this->send('POST', '/2fa_check', ['_auth_code' => TOTP::createFromSecret(self::SECRET)->now(), '_csrf_token' => $this->csrfToken($html)]);

        $this->assertLoggedIn();
        self::assertSame([], $this->mails());
    }

    public function testAnAccountWithoutSecretHasNoSecondFactor(): void
    {
        $account = new TotpAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        self::assertFalse($account->isTotpAuthenticationEnabled());
        self::assertNull($account->getTotpAuthenticationConfiguration());

        $account->setTotpSecret(self::SECRET);
        self::assertTrue($account->isTotpAuthenticationEnabled());
        self::assertSame('alice@example.com', $account->getTotpAuthenticationUsername());
        $configuration = $account->getTotpAuthenticationConfiguration();
        self::assertNotNull($configuration);
        self::assertSame([self::SECRET, 'sha1', 30, 6], [$configuration->getSecret(), $configuration->getAlgorithm(), $configuration->getPeriod(), $configuration->getDigits()]);
    }
}
