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

use Symfony\Contracts\Service\ResetInterface;

/**
 * Switches the check of the hashed passwords off for a while, e.g. around fixtures or the import of legacy accounts.
 *
 * A suspension never outlives the request or the message being handled: a long-running worker starts the next one
 * with the check on.
 */
final class PasswordPolicyGuard implements ResetInterface
{
    private int $suspensions = 0;

    public function disable(): void
    {
        ++$this->suspensions;
    }

    public function enable(): void
    {
        $this->suspensions = max(0, $this->suspensions - 1);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withoutGuard(callable $callback): mixed
    {
        $this->disable();

        try {
            return $callback();
        } finally {
            $this->enable();
        }
    }

    public function isEnabled(): bool
    {
        return 0 === $this->suspensions;
    }

    public function reset(): void
    {
        $this->suspensions = 0;
    }
}
