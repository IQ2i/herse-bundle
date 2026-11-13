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

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * An SVG drawn by bacon/bacon-qr-code, which needs no image extension. The secret never leaves the server.
 *
 * @internal
 */
final class BaconQrCodeRenderer implements QrCodeRendererInterface
{
    public function render(#[\SensitiveParameter] string $content): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd())))->writeString($content);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
