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

namespace IQ2i\HerseBundle\TrustedDevice;

/**
 * What a device is trusted for.
 */
enum TrustLevel: string
{
    /** The device has already logged in successfully: no alert on its next login. */
    case Known = 'known';

    /** The device has passed a second factor and the user asked to trust it: the two-factor feature does not ask for the second factor again. */
    case Verified = 'verified';
}
