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

namespace IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity;

use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Lives apart from the other accounts: it implements the interfaces of scheb, which are not installed in every test run.
 */
#[ORM\Entity]
#[ORM\Table(name: 'two_factor_account')]
class TwoFactorAccount implements UserInterface, PasswordAuthenticatedUserInterface, EmailTwoFactorInterface, TotpTwoFactorInterface
{
    // Assigned by Doctrine.
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'email_auth_code', nullable: true)]
    private ?string $emailAuthCode = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        private string $email,
        #[ORM\Column(nullable: true)]
        private ?string $password = null,
        #[ORM\Column(nullable: true)]
        private ?string $locale = null,
        #[ORM\Column(name: 'email_auth', options: ['default' => true])]
        private bool $emailAuth = true,
        #[ORM\Column(name: 'totp_secret', nullable: true)]
        private ?string $totpSecret = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function isEmailAuthEnabled(): bool
    {
        return $this->emailAuth;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->emailAuthCode = $authCode;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret ? null : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }
}
