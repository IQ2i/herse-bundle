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

use IQ2i\HerseBundle\Event\TwoFactorFailedEvent;
use IQ2i\HerseBundle\Event\TwoFactorSucceededEvent;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The administration asks for a code sent by email, and a device trusted by the administrator skips it afterwards.
 */
final class TwoFactorTest extends SyliusKernelTestCase
{
    /** @var list<TwoFactorSucceededEvent|TwoFactorFailedEvent> */
    private array $twoFactorEvents = [];

    protected static function environment(): string
    {
        return 'test_two_factor';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->twoFactorEvents = [];
        $dispatcher = $this->service(EventDispatcherInterface::class, 'event_dispatcher');
        $dispatcher->addListener(TwoFactorSucceededEvent::class, function (TwoFactorSucceededEvent $event): void {
            $this->twoFactorEvents[] = $event;
        });
        $dispatcher->addListener(TwoFactorFailedEvent::class, function (TwoFactorFailedEvent $event): void {
            $this->twoFactorEvents[] = $event;
        });
    }

    public function testTheCodeIsSentByTheMailerOfTheBundleAndCompletesTheLogin(): void
    {
        $this->createAdmin();

        self::assertSame(200, $this->loginAsAdmin()->getStatusCode());
        $this->assertSecondFactorAsked($this->visitPrivatePage());

        $mails = $this->mails();
        self::assertCount(1, $mails, 'The code only: the device is registered, and the user warned, once the login is complete.');
        self::assertSame('admin@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('no-reply@example.com', $mails[0]->getFrom()[0]->getAddress());
        self::assertSame([], $this->devices());

        $this->submitCode($this->emailedCode());

        $this->assertLoggedIn();
        self::assertCount(1, $this->twoFactorEvents);
        self::assertInstanceOf(TwoFactorSucceededEvent::class, $this->twoFactorEvents[0]);
        self::assertSame('admin', $this->twoFactorEvents[0]->reference?->type);
        self::assertSame('admin', $this->twoFactorEvents[0]->firewall);

        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame(TrustLevel::Known, $devices[0]->getTrustLevel());
        self::assertCount(2, $this->mails(), 'The code, then the new device warning.');
    }

    public function testAWrongCodeIsRefused(): void
    {
        $this->createAdmin();
        $this->loginAsAdmin();

        $this->submitCode('000000');

        $this->assertSecondFactorAsked($this->visitPrivatePage());
        self::assertCount(1, $this->twoFactorEvents);
        self::assertInstanceOf(TwoFactorFailedEvent::class, $this->twoFactorEvents[0]);
        self::assertSame([], $this->devices());
    }

    public function testAVerifiedDeviceSkipsTheSecondFactor(): void
    {
        $this->createAdmin();
        $this->loginAsAdmin();
        $this->submitCode($this->emailedCode(), trustDevice: true);

        self::assertSame(TrustLevel::Verified, $this->devices()[0]->getTrustLevel());
        $codes = \count($this->mails());

        // The browser comes back: the device cookie stays, the session is gone.
        unset($this->cookies['MOCKSESSID']);
        $this->loginAsAdmin();

        $this->assertLoggedIn();
        self::assertCount($codes, $this->mails(), 'No second code is sent.');
        self::assertArrayNotHasKey('trusted_device', $this->cookies, 'The trusted device cookie of scheb is not used.');
    }

    public function testADeviceThatWasNotTrustedIsAskedForTheSecondFactorAgain(): void
    {
        $this->createAdmin();
        $this->loginAsAdmin();
        $this->submitCode($this->emailedCode());

        unset($this->cookies['MOCKSESSID']);
        $this->loginAsAdmin();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testARevokedDeviceIsAskedForTheSecondFactorAgain(): void
    {
        $admin = $this->createAdmin();
        $this->loginAsAdmin();
        $this->submitCode($this->emailedCode(), trustDevice: true);
        $device = $this->devices()[0];

        $trustedDevices = $this->service(TrustedDevices::class, TrustedDevices::class);
        self::assertTrue($trustedDevices->revoke($this->entityManager()->getRepository($admin::class)->find($admin->getId()), (int) $device->getId()));

        unset($this->cookies['MOCKSESSID']);
        $this->loginAsAdmin();

        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testTheShopDoesNotAskForASecondFactor(): void
    {
        $this->createShopUser();

        self::assertSame(200, $this->loginAsCustomer()->getStatusCode());
        self::assertCount(1, $this->devices());
    }

    private function submitCode(string $code, bool $trustDevice = false): Response
    {
        // The form of the bundle, submitted as a browser does: with its CSRF token.
        $form = (string) $this->send('GET', '/admin/2fa')->getContent();
        self::assertStringContainsString('<form action="/admin/2fa_check" method="post"', $form);
        self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $form, $matches));

        return $this->send('POST', '/admin/2fa_check', ['_auth_code' => $code, '_csrf_token' => $matches[1] ?? ''] + ($trustDevice ? ['_trusted' => '1'] : []));
    }

    private function visitPrivatePage(): Response
    {
        return $this->send('GET', '/admin/private');
    }

    private function emailedCode(): string
    {
        foreach (array_reverse($this->mails()) as $mail) {
            if (1 === preg_match('/sign-in code is: (\d+)/', (string) $mail->getHtmlBody(), $matches)) {
                return $matches[1];
            }
        }

        self::fail('An email with the code was expected.');
    }

    private function assertSecondFactorAsked(Response $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/admin/2fa', (string) $response->headers->get('Location'));
    }

    private function assertLoggedIn(): void
    {
        $response = $this->visitPrivatePage();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/admin/private', $response->getContent());
    }
}
