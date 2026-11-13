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

namespace IQ2i\HerseBundle\Tests\TwoFactor;

use IQ2i\HerseBundle\TwoFactor\PendingTotp;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;

final class PendingTotpTest extends TestCase
{
    public function testTheSecretBeingEnrolledIsShownToSchebAsAUserHavingIt(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }

        $pending = new PendingTotp('alice@example.com', 'JBSWY3DPEHPK3PXP');

        self::assertTrue($pending->isTotpAuthenticationEnabled());
        self::assertSame('alice@example.com', $pending->getTotpAuthenticationUsername());
        self::assertSame(['JBSWY3DPEHPK3PXP', 'sha1', 30, 6], [
            $pending->getTotpAuthenticationConfiguration()->getSecret(),
            $pending->getTotpAuthenticationConfiguration()->getAlgorithm(),
            $pending->getTotpAuthenticationConfiguration()->getPeriod(),
            $pending->getTotpAuthenticationConfiguration()->getDigits(),
        ]);
    }
}
