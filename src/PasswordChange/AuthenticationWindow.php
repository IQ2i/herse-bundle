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

use Symfony\Contracts\Service\ResetInterface;

/**
 * Open between the check of the credentials and the end of the login.
 *
 * The password changes made during this window are the automatic rehash of the hasher, not a change by the user.
 *
 * @internal
 */
final class AuthenticationWindow implements ResetInterface
{
    private bool $open = false;

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function reset(): void
    {
        $this->close();
    }
}
