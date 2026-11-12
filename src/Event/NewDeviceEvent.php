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

namespace IQ2i\HerseBundle\Event;

use IQ2i\HerseBundle\User\UserReference;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Dispatched when a user logs in from a device the bundle does not know, once it is registered.
 *
 * The email is null when none is meant to be sent (first device of the user with notify_first_device off, no address).
 * A listener can replace it, set one, or cancel the sending.
 */
final class NewDeviceEvent
{
    private bool $cancelled = false;

    public function __construct(
        public readonly UserReference $reference,
        public readonly object $user,
        public readonly string $firewall,
        public readonly ?string $label,
        public readonly ?string $ip,
        public readonly \DateTimeImmutable $createdAt,
        private ?TemplatedEmail $email,
    ) {
    }

    public function getEmail(): ?TemplatedEmail
    {
        return $this->email;
    }

    public function setEmail(?TemplatedEmail $email): void
    {
        $this->email = $email;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
