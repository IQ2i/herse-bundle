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

namespace IQ2i\HerseBundle\TwoFactor\QrCode;

/**
 * Draws the QR code an authenticator app scans. Alias this interface to your own service to use another library.
 */
interface QrCodeRendererInterface
{
    /**
     * @return string the image as a data URI, usable as the "src" of an <img>
     */
    public function render(#[\SensitiveParameter] string $content): string;
}
