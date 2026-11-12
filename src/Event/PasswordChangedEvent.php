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

use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;
use IQ2i\HerseBundle\User\UserReference;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Dispatched after the password of a declared user has been changed, before the notification email is sent.
 *
 * A listener can replace the email, or cancel the sending.
 */
final class PasswordChangedEvent
{
    private bool $cancelled = false;

    public function __construct(
        public readonly UserReference $reference,
        public readonly object $user,
        public readonly PasswordChangeContext $context,
        public readonly \DateTimeImmutable $changedAt,
        private TemplatedEmail $email,
    ) {
    }

    public function getEmail(): TemplatedEmail
    {
        return $this->email;
    }

    public function setEmail(TemplatedEmail $email): void
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
