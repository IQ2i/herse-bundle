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

use IQ2i\HerseBundle\TrustedDevice\DeviceCookies;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TrustedDevicesServiceTest extends TrustedDevicesKernelTestCase
{
    public function testTheActiveDevicesAreListedMostRecentFirst(): void
    {
        $account = $this->account();
        $this->signIn(userAgent: self::CHROME);
        $this->signIn(userAgent: 'curl/8.4.0');
        $this->signIn(userAgent: self::FIREFOX);
        $this->entityManager()->getConnection()->executeStatement("UPDATE iq2i_herse_trusted_device SET expires_at = '2000-01-01 00:00:00' WHERE id = 2");

        $listed = $this->trustedDevices()->list($account);

        self::assertSame(['Firefox (macOS)', 'Chrome (Windows)'], array_map(static fn ($device): ?string => $device->getLabel(), $listed), 'The expired device is not listed.');
    }

    public function testTheDevicesOfAFirewallCanBeListed(): void
    {
        $account = $this->account();
        $this->signIn(userAgent: self::CHROME);
        $this->signIn(path: '/admin/login');

        $listed = $this->trustedDevices()->list($account, 'admin');

        self::assertCount(1, $listed);
        self::assertSame('admin', $listed[0]->getFirewall());
    }

    public function testTheCurrentDeviceIsTheOneOfTheRequestCookie(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->signIn(userAgent: self::CHROME);
        $this->signIn(path: '/admin/login');

        $this->pushRequest(['herse_device_main' => $token, 'admin_device' => 'x']);
        $current = array_values(array_filter($this->trustedDevices()->list($account), fn ($device): bool => $this->trustedDevices()->isCurrent($device)));

        self::assertCount(1, $current);
        self::assertSame(hash('sha256', $token), $current[0]->getTokenHash());
    }

    public function testNoDeviceIsCurrentOutsideOfARequest(): void
    {
        $account = $this->account();
        $this->signIn();

        self::assertFalse($this->trustedDevices()->isCurrent($this->trustedDevices()->list($account)[0]));
    }

    public function testADeviceIsRevoked(): void
    {
        $account = $this->account();
        $this->signIn();
        $id = (int) $this->devices()[0]->getId();

        self::assertTrue($this->trustedDevices()->revoke($account, $id));

        self::assertSame([], $this->devices());
        self::assertFalse($this->trustedDevices()->revoke($account, $id), 'There is nothing left to revoke.');
    }

    public function testTheDeviceOfAnotherUserCannotBeRevoked(): void
    {
        $this->account();
        $bob = $this->account('bob@example.com');
        $this->signIn();
        $id = (int) $this->devices()[0]->getId();

        self::assertFalse($this->trustedDevices()->revoke($bob, $id));

        self::assertCount(1, $this->devices());
    }

    public function testRevokingTheCurrentDeviceClearsItsCookie(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->pushRequest(['herse_device_main' => $token]);

        $this->trustedDevices()->revoke($account, (int) $this->devices()[0]->getId());
        $response = $this->respond();

        $cookie = $this->cookie($response);
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isCleared());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
    }

    public function testRevokingAnotherDeviceKeepsTheCookie(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->signIn(userAgent: self::CHROME);
        $this->pushRequest(['herse_device_main' => $token]);

        $this->trustedDevices()->revoke($account, (int) $this->devices()[1]->getId());

        self::assertNull($this->cookie($this->respond()));
        self::assertCount(1, $this->devices());
    }

    public function testEveryDeviceCanBeRevokedButTheCurrentOne(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->signIn(userAgent: self::CHROME);
        $this->signIn(path: '/admin/login');
        $this->pushRequest(['herse_device_main' => $token]);

        $revoked = $this->trustedDevices()->revokeAll($account, keepCurrent: true);

        self::assertSame(2, $revoked);
        self::assertSame([hash('sha256', $token)], array_map(static fn ($device): string => $device->getTokenHash(), $this->devices()));
        self::assertNull($this->cookie($this->respond()));
    }

    public function testEveryDeviceCanBeRevoked(): void
    {
        $account = $this->account();
        $this->account('bob@example.com');
        $token = $this->token($this->signIn());
        $this->signIn(userAgent: self::CHROME);
        $this->signIn('bob@example.com');
        $this->pushRequest(['herse_device_main' => $token]);

        $revoked = $this->trustedDevices()->revokeAll($account);

        self::assertSame(2, $revoked);
        self::assertCount(1, $this->devices(), 'The device of Bob stays.');
        $cookie = $this->cookie($this->respond());
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isCleared());
    }

    public function testAUserOfAnUndeclaredClassIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(InMemoryUser::class.'" is not a user class declared in iq2i_herse.users');

        $this->trustedDevices()->list(new InMemoryUser('root', null));
    }

    public function testTheDecisionsDoNotSurviveTheirRequest(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->pushRequest(['herse_device_main' => $token]);
        $this->trustedDevices()->revokeAll($account);

        $this->service(DeviceCookies::class, 'iq2i_herse.trusted_devices.cookies')->reset();

        self::assertNull($this->cookie($this->respond()));
    }

    public function testASubRequestDoesNotWriteTheCookie(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->pushRequest(['herse_device_main' => $token]);
        $this->trustedDevices()->revokeAll($account);

        $response = new Response();
        $this->service(DeviceCookies::class, 'iq2i_herse.trusted_devices.cookies')
            ->onKernelResponse(new ResponseEvent($this->kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST, $response));

        self::assertNull($this->cookie($response));
    }

    private function trustedDevices(): TrustedDevices
    {
        return $this->service(TrustedDevices::class, TrustedDevices::class);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function pushRequest(array $cookies): void
    {
        $this->service(RequestStack::class, 'request_stack')->push(Request::create('/', 'GET', [], $cookies));
    }

    private function respond(): Response
    {
        $response = new Response();
        $this->service(DeviceCookies::class, 'iq2i_herse.trusted_devices.cookies')
            ->onKernelResponse(new ResponseEvent($this->kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
