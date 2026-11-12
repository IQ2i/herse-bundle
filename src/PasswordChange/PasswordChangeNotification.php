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

namespace IQ2i\HerseBundle\PasswordChange;

/**
 * Switches the password change notification off for a while, e.g. around fixtures or imports.
 */
final class PasswordChangeNotification
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
    public function withoutNotification(callable $callback): mixed
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
}
