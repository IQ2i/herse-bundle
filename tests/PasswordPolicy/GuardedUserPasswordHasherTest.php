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
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyGuard;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyViolationException;
use IQ2i\HerseBundle\Tests\PasswordPolicyKernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class GuardedUserPasswordHasherTest extends PasswordPolicyKernelTestCase
{
    public function testThePasswordHasherOfTheProjectIsGuarded(): void
    {
        self::assertInstanceOf(GuardedUserPasswordHasher::class, $this->hasher());
    }

    public function testACompliantPasswordIsHashed(): void
    {
        $hash = $this->hasher()->hashPassword(new InMemoryUser('alice', null), 'Abcdef1!ghij');

        self::assertTrue($this->hasher()->isPasswordValid(new InMemoryUser('alice', $hash), 'Abcdef1!ghij'));
        self::assertFalse($this->hasher()->isPasswordValid(new InMemoryUser('alice', $hash), 'Abcdef1!ghik'));
        self::assertFalse($this->hasher()->needsRehash(new InMemoryUser('alice', $hash)));
    }

    public function testAWeakPasswordIsNeverHashed(): void
    {
        try {
            $this->hasher()->hashPassword(new InMemoryUser('alice', null), 'weak-password');
            self::fail('The password was hashed.');
        } catch (PasswordPolicyViolationException $exception) {
            self::assertSame(['Le mot de passe doit contenir une lettre majuscule.', 'Le mot de passe doit contenir un chiffre.'], $exception->getMessages());
            self::assertStringNotContainsString('weak-password', $exception->getMessage());
        }
    }

    public function testAnEmptyPasswordIsNeverHashed(): void
    {
        $this->expectException(PasswordPolicyViolationException::class);

        $this->hasher()->hashPassword(new InMemoryUser('alice', null), '');
    }

    public function testTheGuardCanBeSuspendedAroundACallback(): void
    {
        $hash = $this->guard()->withoutGuard(fn (): string => $this->hasher()->hashPassword(new InMemoryUser('alice', null), 'weak'));

        self::assertTrue($this->hasher()->isPasswordValid(new InMemoryUser('alice', $hash), 'weak'));
        self::assertTrue($this->guard()->isEnabled());
    }

    public function testTheGuardIsBackOnceTheCallbackFails(): void
    {
        try {
            $this->guard()->withoutGuard(static fn () => throw new \RuntimeException('Import failed.'));
        } catch (\RuntimeException) {
        }

        self::assertTrue($this->guard()->isEnabled());
    }

    public function testNestedSuspensionsAreCounted(): void
    {
        $guard = new PasswordPolicyGuard();
        $guard->disable();
        $guard->disable();
        $guard->enable();
        self::assertFalse($guard->isEnabled());

        $guard->enable();
        $guard->enable();
        self::assertTrue($guard->isEnabled());
    }

    public function testASuspensionDoesNotOutliveTheRequest(): void
    {
        $guard = new PasswordPolicyGuard();
        $guard->disable();
        $guard->reset();

        self::assertTrue($guard->isEnabled());
    }

    public function testAGuardDisabledAtRuntimeHashesEverything(): void
    {
        /** @var PasswordPolicyChecker $checker */
        $checker = $this->kernel->getContainer()->get(PasswordPolicyChecker::class);
        $hasher = new GuardedUserPasswordHasher($this->hasher(), $checker, new PasswordPolicyGuard(), false);

        $this->expectException(PasswordPolicyViolationException::class);

        // The inner hasher is the guarded one of the container: reaching it proves the outer one let the password through.
        $hasher->hashPassword(new InMemoryUser('alice', null), 'weak');
    }

    private function hasher(): UserPasswordHasherInterface
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $this->kernel->getContainer()->get('test.password_hasher');

        return $hasher;
    }

    private function guard(): PasswordPolicyGuard
    {
        /** @var PasswordPolicyGuard $guard */
        $guard = $this->kernel->getContainer()->get(PasswordPolicyGuard::class);

        return $guard;
    }
}
