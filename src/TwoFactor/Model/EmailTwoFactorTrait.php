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

/**
 * The email second factor of a user entity: the mapped code and the methods scheb reads.
 *
 * The entity implements Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface and uses this trait; the column is
 * "email_auth_code". Every user gets the second factor: override isEmailAuthEnabled() to leave some out.
 */
trait EmailTwoFactorTrait
{
    #[ORM\Column(name: 'email_auth_code', type: 'string', length: 255, nullable: true)]
    private ?string $emailAuthCode = null;

    public function isEmailAuthEnabled(): bool
    {
        return true;
    }

    /**
     * Empty: the bundle sends the code to the address declared in iq2i_herse.users.
     */
    public function getEmailAuthRecipient(): string
    {
        return '';
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->emailAuthCode = $authCode;
    }
}
