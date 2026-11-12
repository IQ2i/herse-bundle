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

namespace IQ2i\HerseBundle\RateLimiter;

/**
 * Builds the limiter keys: hashed so that neither an identifier nor an IP is readable in the cache backend.
 *
 * @internal
 */
final class LimiterKey
{
    public static function hash(#[\SensitiveParameter] string $secret, string ...$parts): string
    {
        return hash_hmac('sha256', implode('|', $parts), $secret);
    }
}
