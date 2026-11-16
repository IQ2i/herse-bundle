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

namespace App\Tests;

/**
 * The policy is applied by a form extension of the project on the reset screen of Moka.
 */
final class PasswordPolicyTest extends MokaKernelTestCase
{
    public function testAWeakPasswordIsRefusedByTheResetScreen(): void
    {
        $admin = $this->createAdmin();
        $hash = $admin->getPassword();

        $response = $this->resetPassword($admin, 'weak');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('at least 12 characters', (string) $response->getContent());
        self::assertSame($hash, $this->reload($admin)->getPassword());
    }

    public function testAPasswordThatFollowsThePolicyIsAccepted(): void
    {
        $admin = $this->createAdmin();
        $hash = $admin->getPassword();

        $this->assertRedirectedTo('/admin/login', $this->resetPassword($admin, 'Abcdef1!ghij'));

        self::assertNotSame($hash, $this->reload($admin)->getPassword());
    }

    public function testTheConfirmationOfMokaIsStillChecked(): void
    {
        $admin = $this->createAdmin();

        self::assertSame(422, $this->resetPassword($admin, 'Abcdef1!ghij', 'Abcdef1!ghik')->getStatusCode());
    }
}
