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

use BaconQrCode\Writer;
use IQ2i\HerseBundle\TwoFactor\QrCode\BaconQrCodeRenderer;
use PHPUnit\Framework\TestCase;

final class BaconQrCodeRendererTest extends TestCase
{
    public function testTheQrCodeIsAnInlineSvg(): void
    {
        if (!class_exists(Writer::class)) {
            self::markTestSkipped('bacon/bacon-qr-code is not installed.');
        }

        $uri = (new BaconQrCodeRenderer())->render('otpauth://totp/Example:alice?secret=JBSWY3DPEHPK3PXP');

        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        self::assertStringContainsString('<svg', (string) base64_decode(substr($uri, 26), true));
    }
}
