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

use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;

final class PasswordChangeNotificationOptOutTest extends PasswordChangeKernelTestCase
{
    protected static function environment(): string
    {
        return 'password_change_notification_opt_out';
    }

    public function testAChangeByAnotherUserIsNotNotifiedWhenTheOptionIsOff(): void
    {
        $account = $this->createAccount();
        $this->simulateWebRequest($this->createAccount('admin@example.com'));

        $this->changePassword($account);

        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
    }

    public function testAChangeFromTheConsoleIsNotNotifiedWhenTheOptionIsOff(): void
    {
        $this->changePassword($this->createAccount());

        self::assertSame([], $this->mails());
    }

    public function testAChangeByTheOwnerIsStillNotified(): void
    {
        $account = $this->createAccount();
        $this->simulateWebRequest($account);

        $this->changePassword($account);

        self::assertCount(1, $this->mails());
        self::assertSame(PasswordChangeContext::Self, $this->contextOfTheLastEvent());
    }
}
