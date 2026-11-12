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
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * The device registry replaces the trusted device cookie of scheb: a trusted device skips the second factor.
 */
final class TwoFactorTrustedDevicesTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_bridge';
    }

    public function testADeviceTrustedByTheUserIsVerifiedAndSkipsTheSecondFactor(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode(), trustDevice: true);

        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame(TrustLevel::Verified, $devices[0]->getTrustLevel());

        $this->closeSession();
        $this->signIn();

        $this->assertLoggedIn();
        self::assertCount(2, $this->mails(), 'No second code: the code and the new device warning of the first login only.');
        self::assertCount(1, $this->devices());
        self::assertArrayNotHasKey('trusted_device', $this->cookies, 'The cookie of scheb is not used.');
    }

    public function testADeviceThatWasNotTrustedIsAskedForTheSecondFactorAgain(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode());
        self::assertSame(TrustLevel::Known, $this->devices()[0]->getTrustLevel());

        $this->closeSession();
        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
        self::assertCount(3, $this->mails());
    }

    public function testARevokedDeviceIsAskedForTheSecondFactorAgain(): void
    {
        $account = $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode(), trustDevice: true);
        $device = $this->devices()[0];

        $devices = $this->service(TrustedDevices::class, TrustedDevices::class);
        self::assertTrue($devices->revoke($account, (int) $device->getId()));

        $this->closeSession();
        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testRevokingEveryDeviceBringsTheSecondFactorBack(): void
    {
        $account = $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode(), trustDevice: true);

        self::assertSame(1, $this->service(TrustedDevices::class, TrustedDevices::class)->revokeAll($account));

        $this->closeSession();
        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testTheTrustEndsAFixedTimeAfterTheVerificationWhateverTheLogins(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode(), trustDevice: true);
        self::assertNotNull($this->devices()[0]->getVerifiedAt());
        $this->entityManager()->createQuery('UPDATE '.TrustedDevice::class.' d SET d.verifiedAt = :past')->setParameter('past', new \DateTimeImmutable('-31 days'))->execute();

        $this->closeSession();
        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());

        $this->submitCode($this->emailedCode(), trustDevice: true);
        $this->assertLoggedIn();
        self::assertCount(1, $this->devices(), 'The device is still known.');
        self::assertSame(
            ['Your sign-in code', 'New sign-in to your account', 'Your sign-in code'],
            array_map(static fn (TemplatedEmail $mail): string => $mail->getSubject() ?? '', $this->mails()),
            'A known device is not announced as a new one.',
        );

        $this->closeSession();
        $this->signIn();
        $this->assertLoggedIn();
    }

    public function testAnExpiredDeviceIsAskedForTheSecondFactorAgain(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitCode($this->emailedCode(), trustDevice: true);
        $this->entityManager()->createQuery('UPDATE '.TrustedDevice::class.' d SET d.expiresAt = :past')->setParameter('past', new \DateTimeImmutable('-1 minute'))->execute();

        $this->closeSession();
        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testTheTrustOfAUserIsNotGivenToAnotherUserOfTheSameBrowser(): void
    {
        $this->twoFactorAccount();
        $this->twoFactorAccount('bob@example.com');
        $this->signIn();
        $this->submitCode($this->emailedCode(), trustDevice: true);

        $this->closeSession();
        $this->signIn('bob@example.com');

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testACookieChosenByTheClientNeverSkipsTheSecondFactor(): void
    {
        $this->twoFactorAccount();
        $this->cookies['herse_device_main'] = str_repeat('ab', 32);

        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
        $this->submitCode($this->emailedCode(), trustDevice: true);
        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertNotSame(hash('sha256', str_repeat('ab', 32)), $devices[0]->getTokenHash());
        self::assertNotSame(str_repeat('ab', 32), $this->cookies['herse_device_main']);
    }

    public function testTrustingTheDeviceIsOptional(): void
    {
        $this->twoFactorAccount();
        $this->signIn();

        $this->submitCode($this->emailedCode(), trustDevice: false);

        $this->assertLoggedIn();
        self::assertSame(TrustLevel::Known, $this->devices()[0]->getTrustLevel());
    }
}
