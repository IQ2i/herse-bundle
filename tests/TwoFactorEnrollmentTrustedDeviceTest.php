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
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;
use OTPHP\TOTP;

final class TwoFactorEnrollmentTrustedDeviceTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_screens_totp_devices';
    }

    public function testTheDeviceUsedToEnrollCanBeTrusted(): void
    {
        $this->entityManager()->persist(new TotpAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5])));
        $this->entityManager()->flush();
        $this->signIn();
        $html = (string) $this->send('GET', '/second-factor/setup')->getContent();
        self::assertStringContainsString('name="_trusted"', $html);

        $this->send('POST', '/second-factor/setup', [
            '_auth_code' => TOTP::createFromSecret($this->shownSecret($html))->now(),
            '_csrf_token' => $this->csrfToken($html),
            '_trusted' => '1',
        ]);

        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame(TrustLevel::Verified, $devices[0]->getTrustLevel());
    }
}
