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

final class TwoFactorTotpOverrideTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_totp_override';
    }

    public function testTheProjectOverridesTheDefaultsAndKeepsTheOthers(): void
    {
        $container = $this->kernel->getContainer();

        self::assertSame('Project issuer', $container->getParameter('scheb_two_factor.totp.issuer'));
        self::assertSame(5, $container->getParameter('scheb_two_factor.totp.leeway'));
    }
}
