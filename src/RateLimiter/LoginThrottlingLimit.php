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

enum LoginThrottlingLimit: string
{
    case IdentifierAndIp = 'identifier_and_ip';
    case Ip = 'ip';
    case Identifier = 'identifier';
}
