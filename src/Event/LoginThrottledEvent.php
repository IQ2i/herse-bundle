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

use IQ2i\HerseBundle\RateLimiter\LoginThrottlingLimit;

/**
 * Dispatched when a failed login attempt exhausts one of the login throttling limits.
 *
 * The identifier is the normalized one (trimmed, lowercased), as used to build the limiter keys.
 */
final class LoginThrottledEvent
{
    public function __construct(
        public readonly LoginThrottlingLimit $limit,
        public readonly string $identifier,
        public readonly ?string $ip,
        public readonly ?string $firewall,
    ) {
    }
}
