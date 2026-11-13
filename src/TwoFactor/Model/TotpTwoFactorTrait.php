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

namespace IQ2i\HerseBundle\TwoFactor\Model;

use Doctrine\ORM\Mapping as ORM;
use IQ2i\HerseBundle\TwoFactor\TotpParameters;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;

/**
 * The TOTP second factor of a user entity: the mapped secret and the methods scheb reads.
 *
 * The entity implements TotpTwoFactorInterface and uses this trait; the column is "totp_secret".
 */
trait TotpTwoFactorTrait
{
    #[ORM\Column(name: 'totp_secret', type: 'string', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    abstract public function getUserIdentifier(): string;

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret ? null : TotpParameters::configuration($this->totpSecret);
    }

    public function setTotpSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->totpSecret = $secret;
    }
}
