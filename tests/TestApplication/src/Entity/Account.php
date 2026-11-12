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

namespace IQ2i\HerseBundle\Tests\TestApplication\Entity;

use Doctrine\ORM\Mapping as ORM;
use IQ2i\HerseBundle\Tests\TestApplication\AccountRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
class Account implements UserInterface, PasswordAuthenticatedUserInterface
{
    // Assigned by Doctrine.
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    /** Not mapped: used to check that an unmapped password field is reported. */
    public string $transient = '';

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

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
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
