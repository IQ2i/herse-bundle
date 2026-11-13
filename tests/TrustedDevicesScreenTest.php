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

use IQ2i\HerseBundle\Event\TrustedDevicesRevokedEvent;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use OTPHP\TOTP;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A user sees the devices of their account on the screen of the bundle, and revokes them.
 */
final class TrustedDevicesScreenTest extends TwoFactorKernelTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /** @var list<TrustedDevicesRevokedEvent> */
    private array $revokedEvents = [];

    protected static function environment(): string
    {
        return 'two_factor_screens_totp_devices';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->revokedEvents = [];
        $this->service(EventDispatcherInterface::class, 'test.event_dispatcher')->addListener(TrustedDevicesRevokedEvent::class, function (TrustedDevicesRevokedEvent $event): void {
            $this->revokedEvents[] = $event;
        });
    }

    public function testTheDevicesOfTheFirewallAreListedWithTheCurrentOne(): void
    {
        $this->account();
        $this->logInFromTwoBrowsers();

        $screen = $this->send('GET', '/devices');
        $html = (string) $screen->getContent();

        self::assertSame(200, $screen->getStatusCode());
        self::assertStringContainsString('<title>My devices</title>', $html);
        self::assertStringContainsString('Firefox (macOS)', $html);
        self::assertStringContainsString('Chrome (Windows)', $html);
        self::assertSame(1, substr_count($html, 'This device'));
        self::assertSame(2, preg_match_all('#action="/devices/\d+/revoke"#', $html));
        self::assertStringContainsString('action="/devices/revoke-others"', $html);
    }

    public function testADeviceIsRevoked(): void
    {
        $account = $this->account();
        $this->logInFromTwoBrowsers();
        $html = (string) $this->send('GET', '/devices')->getContent();
        $chrome = $this->deviceId('Chrome (Windows)');

        $response = $this->send('POST', '/devices/'.$chrome.'/revoke', ['_csrf_token' => $this->csrfToken($html)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/devices', $response->headers->get('Location'));
        self::assertSame(['Firefox (macOS)'], array_map(static fn ($device): ?string => $device->getLabel(), $this->devices()));
        self::assertCount(1, $this->revokedEvents);
        self::assertSame((string) $account->getId(), $this->revokedEvents[0]->reference->identifier);
        self::assertSame(1, $this->revokedEvents[0]->count);
        self::assertStringContainsString('The device was revoked.', (string) $this->send('GET', '/devices')->getContent());
    }

    public function testTheOtherDevicesAreRevokedAndTheCurrentOneStays(): void
    {
        $this->account();
        $this->logInFromTwoBrowsers();
        $html = (string) $this->send('GET', '/devices')->getContent();

        $this->send('POST', '/devices/revoke-others', ['_csrf_token' => $this->csrfToken($html)]);

        self::assertSame(['Firefox (macOS)'], array_map(static fn ($device): ?string => $device->getLabel(), $this->devices()));
        $html = (string) $this->send('GET', '/devices')->getContent();
        self::assertStringContainsString('The other devices were revoked.', $html);
        self::assertStringNotContainsString('action="/devices/revoke-others"', $html);

        $this->send('POST', '/devices/revoke-others', ['_csrf_token' => $this->csrfToken($html)]);
        self::assertStringContainsString('There was no other device to revoke.', (string) $this->send('GET', '/devices')->getContent());
    }

    public function testASubmissionWithoutTheCsrfTokenRevokesNothing(): void
    {
        $this->account();
        $this->logInFromTwoBrowsers();

        self::assertSame(403, $this->send('POST', '/devices/'.$this->deviceId('Chrome (Windows)').'/revoke')->getStatusCode());
        self::assertSame(403, $this->send('POST', '/devices/revoke-others')->getStatusCode());
        self::assertCount(2, $this->devices());
    }

    public function testTheDeviceOfSomebodyElseIsNotFound(): void
    {
        $this->account();
        $this->logInFromTwoBrowsers();
        $html = (string) $this->send('GET', '/devices')->getContent();
        $this->account('bob@example.com');
        $this->cookies = [];
        $this->logInWith('bob@example.com', self::CHROME);
        $bobHtml = (string) $this->send('GET', '/devices')->getContent();
        self::assertNotSame('', $html);

        $response = $this->send('POST', '/devices/'.$this->deviceId('Firefox (macOS)').'/revoke', ['_csrf_token' => $this->csrfToken($bobHtml)]);

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(3, $this->devices());
        self::assertSame(404, $this->send('POST', '/devices/999/revoke', ['_csrf_token' => $this->csrfToken($bobHtml)])->getStatusCode());
    }

    public function testTheScreenIsClosedToAVisitor(): void
    {
        self::assertSame(401, $this->send('GET', '/devices')->getStatusCode());
    }

    public function testAnAccountWithoutDeviceIsToldSo(): void
    {
        $this->account();
        $this->logInWith('alice@example.com', self::FIREFOX);
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM iq2i_herse_trusted_device');

        self::assertStringContainsString('No device is registered yet.', (string) $this->send('GET', '/devices')->getContent());
    }

    private function account(string $email = 'alice@example.com'): TotpAccount
    {
        $account = new TotpAccount($email, password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $account->setTotpSecret(self::SECRET);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    /**
     * Chrome logs in and leaves, then Firefox logs in: it is the browser of the following requests.
     */
    private function logInFromTwoBrowsers(): void
    {
        $this->logInWith('alice@example.com', self::CHROME);
        $this->cookies = [];
        $this->logInWith('alice@example.com', self::FIREFOX);
    }

    private function logInWith(string $email, string $userAgent): void
    {
        $server = ['HTTP_USER_AGENT' => $userAgent];
        $this->send('POST', '/login', json: ['username' => $email, 'password' => 'secret'], server: $server);
        $html = (string) $this->send('GET', '/2fa', server: $server)->getContent();
        $this->send('POST', '/2fa_check', ['_auth_code' => TOTP::createFromSecret(self::SECRET)->now(), '_csrf_token' => $this->csrfToken($html)], server: $server);
    }

    private function deviceId(string $label): int
    {
        foreach ($this->devices() as $device) {
            if ($label === $device->getLabel() && null !== $device->getId()) {
                return $device->getId();
            }
        }

        self::fail(\sprintf('The device "%s" was expected.', $label));
    }
}
