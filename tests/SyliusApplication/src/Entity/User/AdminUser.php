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

namespace App\Entity\User;

use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Sylius\Component\Core\Model\AdminUser as BaseAdminUser;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_admin_user')]
class AdminUser extends BaseAdminUser implements TwoFactorInterface
{
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $emailAuthEnabled = false;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $authCode = null;

    public function isEmailAuthEnabled(): bool
    {
        return $this->emailAuthEnabled;
    }

    public function enableEmailAuth(): void
    {
        $this->emailAuthEnabled = true;
    }

    public function getEmailAuthRecipient(): string
    {
        return (string) $this->getEmail();
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->authCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->authCode = $authCode;
    }
}
