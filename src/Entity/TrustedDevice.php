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

namespace IQ2i\HerseBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;

/**
 * A browser trusted by a user on a firewall.
 *
 * The user is a neutral reference (type + identifier), never a Doctrine relation. Only the hash of the cookie token
 * is stored: a leak of the table cannot be used to forge a cookie. A browser shared by several users has one row per user.
 *
 * Not final: Doctrine generates a proxy for every mapped class unless the native lazy objects are enabled.
 */
#[ORM\Entity]
#[ORM\Table(name: 'iq2i_herse_trusted_device')]
#[ORM\UniqueConstraint(name: 'uniq_iq2i_herse_device', columns: ['user_type', 'user_identifier', 'firewall', 'token_hash'])]
#[ORM\Index(name: 'idx_iq2i_herse_device_expires_at', columns: ['expires_at'])]
class TrustedDevice
{
    // Assigned by Doctrine.
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastUsedAt;

    #[ORM\Column(name: 'trust_level', length: 16, enumType: TrustLevel::class)]
    private TrustLevel $trustLevel = TrustLevel::Known;

    #[ORM\Column(name: 'verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null; // @phpstan-ignore property.unusedType

    public function __construct(
        #[ORM\Column(name: 'user_type', length: 64)]
        private readonly string $userType,
        #[ORM\Column(name: 'user_identifier', length: 255)]
        private readonly string $userIdentifier,
        #[ORM\Column(length: 64)]
        private readonly string $firewall,
        #[ORM\Column(name: 'token_hash', length: 64)]
        private readonly string $tokenHash,
        #[ORM\Column(length: 100, nullable: true)]
        private readonly ?string $label,
        #[ORM\Column(name: 'last_ip', length: 45, nullable: true)]
        private ?string $lastIp,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $expiresAt,
    ) {
        $this->lastUsedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getFirewall(): string
    {
        return $this->firewall;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * Derived from the User-Agent, null when the browser sent none. Display only: a device is never recognised by it.
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getLastIp(): ?string
    {
        return $this->lastIp;
    }

    public function getTrustLevel(): TrustLevel
    {
        return $this->trustLevel;
    }

    /**
     * When the device last passed a second factor and was trusted by the user. The second factor is skipped for
     * two_factor.trusted_lifetime from this date, whatever the logins made since.
     */
    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }
}
