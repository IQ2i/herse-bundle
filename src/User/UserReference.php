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

namespace IQ2i\HerseBundle\User;

/**
 * Neutral reference to a project user: the declared type and the identifier, never a Doctrine relation.
 */
final class UserReference
{
    public function __construct(
        public readonly string $type,
        public readonly string $identifier,
    ) {
    }
}
