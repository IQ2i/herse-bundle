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

/**
 * Dispatched once the second factor of a user was removed, e.g. after a lost phone: the user enrolls a new one.
 *
 * The reference is null when the user class is not declared in iq2i_herse.users.
 */
final class TwoFactorResetEvent
{
    /**
     * @param object|null $by the user who reset it, null from the console
     */
    public function __construct(
        public readonly ?UserReference $reference,
        public readonly object $user,
        public readonly ?object $by,
    ) {
    }
}
