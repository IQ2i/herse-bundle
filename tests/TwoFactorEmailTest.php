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

use IQ2i\HerseBundle\Event\TwoFactorFailedEvent;
use IQ2i\HerseBundle\Event\TwoFactorSucceededEvent;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;

final class TwoFactorEmailTest extends TwoFactorKernelTestCase
{
    public function testTheCodeIsEmailedAndOpensTheSession(): void
    {
        $account = $this->twoFactorAccount();

        $this->signIn();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('alice@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('no-reply@example.com', $mails[0]->getFrom()[0]->getAddress());
        self::assertSame('Example', $mails[0]->getFrom()[0]->getName());
        self::assertSame('Your sign-in code', $mails[0]->getSubject());
        $code = $this->emailedCode();
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertStringContainsString('Example shop', (string) $mails[0]->getHtmlBody());

        $this->submitCode($code);

        $this->assertLoggedIn();
        self::assertSame((string) $account->getId(), $this->twoFactorEvents[0]->reference?->identifier);
    }

    public function testTheCodeEmailFollowsTheLocaleOfTheUser(): void
    {
        $this->twoFactorAccount(locale: 'fr');

        $this->signIn();

        self::assertSame('Votre code de connexion', $this->mails()[0]->getSubject());
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->emailedCode());
    }

    public function testAWrongCodeIsRefused(): void
    {
        $this->twoFactorAccount();
        $this->signIn();

        $response = $this->submitCode('000000');

        $this->assertSecondFactorAsked($response);
        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testTheSuccessAndTheFailureOfTheSecondFactorAreRelayed(): void
    {
        $account = $this->twoFactorAccount();
        $this->signIn();

        $this->submitCode('000000');
        $this->submitCode($this->emailedCode());

        self::assertCount(2, $this->twoFactorEvents);
        [$failure, $success] = $this->twoFactorEvents;
        self::assertInstanceOf(TwoFactorFailedEvent::class, $failure);
        self::assertInstanceOf(TwoFactorSucceededEvent::class, $success);
        foreach ([$failure, $success] as $event) {
            self::assertSame('customer', $event->reference?->type);
            self::assertSame((string) $account->getId(), $event->reference->identifier);
            self::assertSame('main', $event->firewall);
            self::assertSame('203.0.113.7', $event->ip);
            self::assertSame($account->getEmail(), $event->user instanceof \Stringable ? (string) $event->user : $account->getEmail());
        }
    }

    public function testTheDeviceIsRegisteredOnceTheSecondFactorIsPassed(): void
    {
        $this->twoFactorAccount();

        $this->signIn();

        self::assertSame([], $this->devices());
        self::assertCount(1, $this->mails(), 'Only the code is sent while the second factor is pending.');
        self::assertSame([], $this->deviceEvents);

        $this->submitCode($this->emailedCode());

        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame(TrustLevel::Known, $devices[0]->getTrustLevel());
        $mails = $this->mails();
        self::assertCount(2, $mails);
        self::assertSame('New sign-in to your account', $mails[1]->getSubject());
        self::assertCount(1, $this->deviceEvents);
        self::assertArrayHasKey('herse_device_main', $this->cookies);
    }

    public function testNothingIsRegisteredWhenTheSecondFactorFails(): void
    {
        $this->twoFactorAccount();
        $this->signIn();

        $this->submitCode('000000');

        self::assertSame([], $this->devices());
        self::assertCount(1, $this->mails(), 'The user is not warned about a device that never logged in.');
        self::assertArrayNotHasKey('herse_device_main', $this->cookies);
    }

    public function testAnAccountWithoutSecondFactorLogsInDirectly(): void
    {
        $this->twoFactorAccount(emailAuth: false);

        $this->signIn();

        $this->assertLoggedIn();
        self::assertCount(1, $this->devices());
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('New sign-in to your account', $mails[0]->getSubject());
        self::assertSame([], $this->twoFactorEvents);
    }

    public function testAWrongPasswordNeverSendsACode(): void
    {
        $this->twoFactorAccount();

        $this->signIn(password: 'wrong');

        self::assertSame([], $this->mails());
        self::assertSame(401, $this->visitPrivatePage()->getStatusCode());
    }
}
