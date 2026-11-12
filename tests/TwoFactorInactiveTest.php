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

namespace IQ2i\HerseBundle\Tests;

/**
 * scheb installed and registered, but the two_factor feature is off and the firewall asks for nothing.
 */
final class TwoFactorInactiveTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_inactive';
    }

    public function testTheLoginAndTheDevicesAreUnchanged(): void
    {
        $this->twoFactorAccount();

        $this->signIn();

        $this->assertLoggedIn();
        self::assertCount(1, $this->devices());
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('New sign-in to your account', $mails[0]->getSubject());
        self::assertSame([], $this->twoFactorEvents);
    }

    public function testTheBundleDeclaresNothingForTheSecondFactor(): void
    {
        $container = $this->kernel->getContainer();

        self::assertFalse($container->has('iq2i_herse.two_factor.event_relay'));
        self::assertFalse($container->has('iq2i_herse.two_factor.auth_code_mailer'));
        self::assertFalse($container->has('iq2i_herse.two_factor.trusted_device_manager'));
    }
}
