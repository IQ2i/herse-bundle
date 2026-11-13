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

namespace IQ2i\HerseBundle\PasswordPolicy;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Refuses to hash a password that breaks the policy, whatever the code path: a form, a command, an API.
 *
 * The validation of a form or of a command reports the broken rules first; this is the net under a path that forgot it.
 * The rehash of an existing password at login does not go through this service.
 */
final class GuardedUserPasswordHasher implements UserPasswordHasherInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $inner,
        private readonly PasswordPolicyChecker $checker,
        private readonly PasswordPolicyGuard $guard,
        private readonly bool $enabled,
    ) {
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        if ($this->enabled && $this->guard->isEnabled()) {
            // Local rules only: a lookup of the data breaches would add a network call to every hash.
            $messages = $this->checker->messages($plainPassword, false);
            if ([] !== $messages) {
                throw new PasswordPolicyViolationException($messages);
            }
        }

        return $this->inner->hashPassword($user, $plainPassword);
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->inner->isPasswordValid($user, $plainPassword);
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return $this->inner->needsRehash($user);
    }
}
