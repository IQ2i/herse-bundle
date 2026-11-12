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

use IQ2i\HerseBundle\Entity\TrustedDevice;

/**
 * With notify_before_second_factor, the user is warned as soon as the password is accepted from an unknown device.
 */
final class TwoFactorEarlyAlertTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_early';
    }

    public function testTheUserIsWarnedBeforeTheSecondFactorAndOnlyOnce(): void
    {
        $this->twoFactorAccount();

        $this->signIn();

        $mails = $this->mails();
        self::assertCount(2, $mails);
        self::assertSame(['New sign-in to your account', 'Your sign-in code'], $this->subjects());
        self::assertCount(1, $this->deviceEvents);
        self::assertSame([], $this->devices(), 'The device is still registered after the second factor only.');

        $this->submitCode($this->emailedCode());

        self::assertCount(2, $this->mails(), 'The end of the login does not warn again.');
        self::assertCount(1, $this->deviceEvents);
        self::assertCount(1, $this->devices());
    }

    public function testTheUserIsWarnedEvenWhenTheSecondFactorFails(): void
    {
        $this->twoFactorAccount();

        $this->signIn();
        $this->submitCode('000000');

        self::assertCount(2, $this->mails());
        self::assertSame([], $this->devices());
    }

    public function testAKnownDeviceIsNotWarned(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode());

        $this->closeSession();
        $this->signIn();

        self::assertSame(['New sign-in to your account', 'Your sign-in code', 'Your sign-in code'], $this->subjects(), 'The warning and the first code, then the second code.');
        self::assertCount(1, $this->deviceEvents);
    }

    public function testAnExpiredDeviceIsWarnedAgain(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode());
        $this->entityManager()->createQuery('UPDATE '.TrustedDevice::class.' d SET d.expiresAt = :past')->setParameter('past', new \DateTimeImmutable('-1 minute'))->execute();

        $this->closeSession();
        $this->signIn();

        self::assertSame(['New sign-in to your account', 'Your sign-in code', 'New sign-in to your account', 'Your sign-in code'], $this->subjects());
    }

    public function testEveryPasswordAcceptedFromAnUnknownDeviceWarnsTheUser(): void
    {
        $this->twoFactorAccount();

        $this->signIn();
        $this->closeSession();
        $this->signIn();

        self::assertSame(['New sign-in to your account', 'Your sign-in code', 'New sign-in to your account', 'Your sign-in code'], $this->subjects());
    }

    public function testTheFirstDeviceOfAUserFollowsNotifyFirstDevice(): void
    {
        $this->twoFactorAccount();

        $this->signIn();

        self::assertCount(2, $this->mails(), 'notify_first_device is on by default.');
    }

    /**
     * @return list<string|null>
     */
    private function subjects(): array
    {
        return array_map(static fn ($mail): ?string => $mail->getSubject(), $this->mails());
    }
}
