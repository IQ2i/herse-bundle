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

namespace IQ2i\HerseBundle\TwoFactor;

use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;

/**
 * The TOTP parameters every authenticator app understands, in one place: the secret of an account and the one being
 * enrolled must be read the same way.
 */
final class TotpParameters
{
    public const ALGORITHM = TotpConfiguration::ALGORITHM_SHA1;
    public const PERIOD = 30;
    public const DIGITS = 6;

    public static function configuration(#[\SensitiveParameter] string $secret): TotpConfiguration
    {
        return new TotpConfiguration($secret, self::ALGORITHM, self::PERIOD, self::DIGITS);
    }
}
