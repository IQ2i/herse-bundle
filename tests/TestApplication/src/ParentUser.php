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

namespace IQ2i\HerseBundle\Tests\TestApplication;

abstract class ParentUser
{
    public int $id = 1;
    public string $email = 'user@example.com';

    public function __construct(
        private readonly ?string $plainPassword = null,
        private readonly ?string $password = null,
    ) {
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }
}
