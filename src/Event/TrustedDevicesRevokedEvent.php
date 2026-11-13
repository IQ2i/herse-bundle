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
 * Dispatched once devices of a user were revoked: by the user, by the reset of their second factor or by the change of
 * their password. Not dispatched when nothing was revoked, nor by the purge of the expired devices.
 */
final class TrustedDevicesRevokedEvent
{
    /**
     * @param int $count the number of devices revoked
     */
    public function __construct(
        public readonly UserReference $reference,
        public readonly int $count,
    ) {
    }
}
