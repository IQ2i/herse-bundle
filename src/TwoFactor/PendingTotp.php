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

use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;

/**
 * The secret being enrolled, shown to scheb as a user: it checks a code and builds a QR code from a user only, and the
 * secret must not be on the account before a first code proved the authenticator app has it.
 *
 * @internal
 */
final class PendingTotp implements TwoFactorInterface
{
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $secret,
    ) {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return true;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        return TotpParameters::configuration($this->secret);
    }
}
