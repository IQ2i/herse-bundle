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

final class TwoFactorTotpIssuerTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_totp_issuer';
    }

    protected function setUp(): void
    {
        putenv('HERSE_TOTP_ISSUER=Example shop (preprod)');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('HERSE_TOTP_ISSUER');

        parent::tearDown();
    }

    public function testTheIssuerComesFromAnEnvironmentVariable(): void
    {
        self::assertSame('Example shop (preprod)', $this->kernel->getContainer()->getParameter('scheb_two_factor.totp.issuer'));
    }
}
