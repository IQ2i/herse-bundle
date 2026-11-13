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

namespace IQ2i\HerseBundle\Tests\PasswordPolicy;

use IQ2i\HerseBundle\PasswordPolicy\GuardedUserPasswordHasher;
use IQ2i\HerseBundle\Tests\HerseKernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UnguardedPasswordHasherTest extends HerseKernelTestCase
{
    protected static function environment(): string
    {
        return 'password_policy_unguarded';
    }

    public function testAWeakPasswordIsHashedOnceTheGuardIsDisabled(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->kernel->getContainer()->get('test.password_hasher');

        self::assertNotInstanceOf(GuardedUserPasswordHasher::class, $hasher);
        self::assertNotSame('', $hasher->hashPassword(new InMemoryUser('alice', null), 'weak'));
    }
}
