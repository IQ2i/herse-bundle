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
 * Dispatched once a user enrolled a second factor on the screen of the bundle. The secret is never part of the event.
 *
 * The reference is null when the user class is not declared in iq2i_herse.users.
 */
final class TwoFactorEnabledEvent
{
    public function __construct(
        public readonly ?UserReference $reference,
        public readonly object $user,
        public readonly string $firewall,
        public readonly ?string $ip,
    ) {
    }
}
