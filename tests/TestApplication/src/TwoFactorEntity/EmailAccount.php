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
use IQ2i\HerseBundle\TwoFactor\Model\EmailTwoFactorTrait;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What a project writes for the email second factor: the interface of scheb and the trait of the bundle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_account')]
class EmailAccount implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    use EmailTwoFactorTrait;

    // Assigned by Doctrine.
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    public function __construct(
        #[ORM\Column(unique: true)]
        private string $email,
        #[ORM\Column(nullable: true)]
        private ?string $password = null,
        #[ORM\Column(nullable: true)]
        private ?string $locale = null,
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
}
