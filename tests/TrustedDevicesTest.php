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

use IQ2i\HerseBundle\Event\NewDeviceEvent;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;

final class TrustedDevicesTest extends TrustedDevicesKernelTestCase
{
    public function testAnUnknownDeviceIsRegisteredAndTheUserIsWarned(): void
    {
        $account = $this->account();

        $response = $this->signIn();

        $token = $this->token($response);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        $devices = $this->devices();
        self::assertCount(1, $devices);
        $device = $devices[0];
        self::assertSame('customer', $device->getUserType());
        self::assertSame((string) $account->getId(), $device->getUserIdentifier());
        self::assertSame('main', $device->getFirewall());
        self::assertSame(hash('sha256', $token), $device->getTokenHash());
        self::assertNotSame($token, $device->getTokenHash());
        self::assertSame('Firefox (macOS)', $device->getLabel());
        self::assertSame('203.0.113.7', $device->getLastIp());
        self::assertSame(TrustLevel::Known, $device->getTrustLevel());
        self::assertEqualsWithDelta(time() + 30 * 86400, $device->getExpiresAt()->getTimestamp(), 5);

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('alice@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('New sign-in to your account', $mails[0]->getSubject());
        $body = (string) $mails[0]->getHtmlBody();
        self::assertStringContainsString('Device: Firefox (macOS)', $body);
        self::assertStringContainsString('IP address: 203.0.113.7', $body);
        self::assertStringContainsString('Date and time: '.date('Y-m-d H:i'), $body);
        self::assertStringNotContainsString($token, $body);
        self::assertStringNotContainsString('href=', $body);

        self::assertCount(1, $this->events);
        self::assertSame('main', $this->events[0]->firewall);
        self::assertSame('Firefox (macOS)', $this->events[0]->label);
        self::assertSame('203.0.113.7', $this->events[0]->ip);
        self::assertSame($account, $this->events[0]->user);
        self::assertSame('customer', $this->events[0]->reference->type);
    }

    public function testTheCookieIsHardened(): void
    {
        $this->account();

        $cookie = $this->cookie($this->signIn(), 'herse_device_main');

        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertEqualsWithDelta(time() + 30 * 86400, $cookie->getExpiresTime(), 5);
    }

    public function testAKnownDeviceIsNotWarnedAgain(): void
    {
        $this->account();
        $token = $this->token($this->signIn());
        $first = $this->devices()[0];
        $this->resetMails();
        $this->events = [];

        $response = $this->signIn(cookies: ['herse_device_main' => $token], ip: '198.51.100.9');

        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame('198.51.100.9', $devices[0]->getLastIp());
        self::assertGreaterThanOrEqual($first->getLastUsedAt(), $devices[0]->getLastUsedAt());
        self::assertEquals($first->getCreatedAt(), $devices[0]->getCreatedAt());
        self::assertSame($token, $this->token($response));
    }

    public function testTheExpirationSlidesWithEachLogin(): void
    {
        $this->account();
        $token = $this->token($this->signIn());
        $this->shiftExpiration(new \DateTimeImmutable('+1 day'));

        $this->signIn(cookies: ['herse_device_main' => $token]);

        self::assertEqualsWithDelta(time() + 30 * 86400, $this->devices()[0]->getExpiresAt()->getTimestamp(), 5);
    }

    public function testAnExpiredDeviceIsUnknownAgain(): void
    {
        $this->account();
        $token = $this->token($this->signIn());
        $this->shiftExpiration(new \DateTimeImmutable('-1 minute'));
        $this->resetMails();

        $response = $this->signIn(cookies: ['herse_device_main' => $token]);

        self::assertCount(1, $this->mails());
        $devices = $this->devices();
        self::assertCount(1, $devices, 'The expired row must be replaced, not kept beside the new one.');
        self::assertGreaterThan(new \DateTimeImmutable(), $devices[0]->getExpiresAt());
        self::assertNotSame($token, $this->token($response), 'A token is never reused: a new device gets a new one.');
    }

    public function testTheExpiredDevicesOfTheUserAreCleanedAtLogin(): void
    {
        $this->account();
        $this->signIn(userAgent: self::CHROME);
        $this->shiftExpiration(new \DateTimeImmutable('-1 day'));
        $this->account('bob@example.com');
        $this->signIn('bob@example.com');

        $this->signIn();

        $labels = array_map(static fn ($device): ?string => $device->getLabel(), $this->devices());
        self::assertSame(['Firefox (macOS)', 'Firefox (macOS)'], $labels, 'Only the expired device of Alice goes, the one of Bob stays.');
    }

    public function testARevokedDeviceIsUnknownAgain(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->resetMails();

        $revoked = $this->service(TrustedDevices::class, TrustedDevices::class)->revoke($account, (int) $this->devices()[0]->getId());
        $this->signIn(cookies: ['herse_device_main' => $token]);

        self::assertTrue($revoked);
        self::assertCount(1, $this->mails());
    }

    public function testADeviceTrustedOnOneFirewallIsUnknownOnTheOther(): void
    {
        $this->account();
        $this->signIn();
        $this->resetMails();

        $response = $this->signIn(path: '/admin/login');

        self::assertCount(1, $this->mails());
        self::assertSame(['main', 'admin'], array_map(static fn ($device): string => $device->getFirewall(), $this->devices()));
        $cookie = $this->cookie($response, 'admin_device');
        self::assertNotNull($cookie);
        self::assertEqualsWithDelta(time() + 7 * 86400, $cookie->getExpiresTime(), 5, 'Each firewall has its own lifetime.');
        self::assertNull($this->cookie($response, 'herse_device_main'));
    }

    public function testAFirewallThatIsNotWatchedRegistersNothing(): void
    {
        $this->account();

        $response = $this->signIn(path: '/public/login');

        self::assertSame([], $response->headers->getCookies());
        self::assertSame([], $this->devices());
        self::assertSame([], $this->mails());
    }

    public function testABrowserSharedByTwoUsersGetsATokenPerLogin(): void
    {
        $this->account();
        $this->account('bob@example.com');
        $token = $this->token($this->signIn());
        $this->resetMails();

        $response = $this->signIn('bob@example.com', cookies: ['herse_device_main' => $token]);

        self::assertNotSame($token, $this->token($response));
        self::assertCount(1, $this->mails(), 'The browser is unknown to Bob.');
        self::assertCount(2, $this->devices());
    }

    public function testATokenChosenByTheClientIsNeverAdopted(): void
    {
        $this->account();
        $chosen = str_repeat('a', 64);

        $response = $this->signIn(cookies: ['herse_device_main' => $chosen]);

        self::assertNotSame($chosen, $this->token($response));
        self::assertSame(hash('sha256', $this->token($response)), $this->devices()[0]->getTokenHash());
    }

    public function testAMalformedCookieIsReplaced(): void
    {
        $this->account();

        $response = $this->signIn(cookies: ['herse_device_main' => 'not-a-token']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->token($response));
        self::assertCount(1, $this->mails());
    }

    public function testTheEmailFollowsTheLocaleOfTheUser(): void
    {
        $this->account(locale: 'fr');

        $this->signIn();

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('Nouvelle connexion à votre compte', $mails[0]->getSubject());
        self::assertStringContainsString('Appareil : Firefox (macOS)', (string) $mails[0]->getHtmlBody());
    }

    public function testAnUnknownUserAgentIsLabelledInTheLanguageOfTheEmail(): void
    {
        $this->account(locale: 'fr');

        $this->signIn(userAgent: '');

        self::assertNull($this->devices()[0]->getLabel());
        self::assertStringContainsString('Appareil : Appareil inconnu', (string) $this->mails()[0]->getHtmlBody());
    }

    public function testAListenerCanCancelTheEmail(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(NewDeviceEvent::class, static function (NewDeviceEvent $event): void {
            $event->cancel();
        });
        $this->account();

        $this->signIn();

        self::assertCount(1, $this->events);
        self::assertSame([], $this->mails());
        self::assertCount(1, $this->devices(), 'The device is registered whatever the listener does.');
    }

    public function testAListenerCanReplaceTheEmail(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(NewDeviceEvent::class, static function (NewDeviceEvent $event): void {
            $event->setEmail((new TemplatedEmail())->from('support@example.com')->to('alice@example.com')->subject('Custom subject')->text('Custom body'));
        });
        $this->account();

        $this->signIn();

        self::assertSame(['Custom subject'], array_map(static fn (TemplatedEmail $mail): string => $mail->getSubject() ?? '', $this->mails()));
    }

    public function testATransportFailureNeverFailsTheLogin(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(MessageEvent::class, static function (): void {
            throw new TransportException('The SMTP server is down.');
        });
        $this->account();

        $response = $this->signIn();

        self::assertCount(1, $this->devices());
        self::assertNotNull($this->cookie($response));
        self::assertSame(['The security email could not be sent.'], $this->logger()->messages('error'));
    }

    public function testAFailingListenerNeverFailsTheLogin(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(NewDeviceEvent::class, static function (): void {
            throw new \RuntimeException('Broken listener.');
        });
        $this->account();

        $response = $this->signIn();

        self::assertNotNull($this->cookie($response));
        self::assertSame(['The trusted device could not be registered.'], $this->logger()->messages('error'));
    }

    public function testAFailedLoginRegistersNothing(): void
    {
        $this->account();

        $response = $this->loginResponse('alice@example.com', 'wrong');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->devices());
        self::assertSame([], $this->mails());
    }

    private function shiftExpiration(\DateTimeImmutable $expiresAt): void
    {
        $this->entityManager()->getConnection()->executeStatement('UPDATE iq2i_herse_trusted_device SET expires_at = ?', [$expiresAt->format('Y-m-d H:i:s')]);
    }
}
