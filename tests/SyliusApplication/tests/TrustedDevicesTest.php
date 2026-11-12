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

use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;

/**
 * Sylius has two populations of users, so the registry keeps two user types, one per firewall.
 */
final class TrustedDevicesTest extends SyliusKernelTestCase
{
    public function testAnUnknownAdministratorDeviceIsRegisteredAndTheAdministratorIsWarned(): void
    {
        $admin = $this->createAdmin();

        self::assertSame(200, $this->loginAsAdmin()->getStatusCode());

        self::assertArrayHasKey('herse_device_admin', $this->cookies);
        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame('admin', $devices[0]->getUserType());
        self::assertSame((string) $admin->getId(), $devices[0]->getUserIdentifier());
        self::assertSame('admin', $devices[0]->getFirewall());
        self::assertSame(TrustLevel::Known, $devices[0]->getTrustLevel());
        self::assertSame('Firefox (macOS)', $devices[0]->getLabel());

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('admin@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('New sign-in to your account', $mails[0]->getSubject());
    }

    public function testAnUnknownCustomerDeviceIsRegisteredAndTheCustomerIsWarned(): void
    {
        $user = $this->createShopUser();

        self::assertSame(200, $this->loginAsCustomer()->getStatusCode());

        self::assertArrayHasKey('herse_device_shop', $this->cookies);
        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame('shop', $devices[0]->getUserType());
        self::assertSame((string) $user->getId(), $devices[0]->getUserIdentifier());
        self::assertSame('shop', $devices[0]->getFirewall());

        // The address is read through "customer.email": the shop user has none of its own.
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('customer@example.com', $mails[0]->getTo()[0]->getAddress());
    }

    public function testAKnownDeviceIsNotWarnedAgain(): void
    {
        $this->createShopUser();
        $this->loginAsCustomer();
        $this->resetMails();

        self::assertSame(200, $this->loginAsCustomer(ip: '198.51.100.9')->getStatusCode());

        self::assertSame([], $this->mails());
        self::assertCount(1, $this->devices());
    }

    public function testTheTrustGivenOnTheShopDoesNotApplyToTheAdministration(): void
    {
        $this->createAdmin('same@example.com');
        $this->createShopUser('same@example.com');
        $this->loginAsCustomer('same@example.com');
        $shopToken = $this->cookies['herse_device_shop'];
        $this->resetMails();

        // The shop cookie is also offered to the administration, which must not recognise it.
        $this->cookies['herse_device_admin'] = $shopToken;
        self::assertSame(200, $this->loginAsAdmin('same@example.com')->getStatusCode());

        self::assertCount(1, $this->mails());
        $devices = $this->devices();
        self::assertSame(['admin', 'shop'], array_map(static fn ($device): string => $device->getFirewall(), [$devices[1], $devices[0]]));
        self::assertNotSame($shopToken, $this->cookies['herse_device_admin']);
    }

    public function testARevokedDeviceIsUnknownAgain(): void
    {
        $user = $this->createShopUser();
        $this->loginAsCustomer();
        $device = $this->devices()[0];
        $this->resetMails();

        self::assertTrue($this->service(TrustedDevices::class, TrustedDevices::class)->revoke($this->entityManager()->getRepository($user::class)->find($user->getId()), (int) $device->getId()));
        self::assertSame(200, $this->loginAsCustomer()->getStatusCode());

        self::assertCount(1, $this->mails());
    }

    public function testAnExpiredDeviceIsUnknownAgain(): void
    {
        $this->createAdmin();
        $this->loginAsAdmin();
        $this->entityManager()->getConnection()->executeStatement('UPDATE iq2i_herse_trusted_device SET expires_at = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $this->resetMails();

        $this->loginAsAdmin();

        self::assertCount(1, $this->mails());
        self::assertCount(1, $this->devices());
    }

    public function testARememberMeLoginNeverRegistersADevice(): void
    {
        $this->createAdmin();
        $this->send('POST', '/admin/login-check', json: ['_username' => 'admin@example.com', '_password' => self::PASSWORD, '_remember_me' => true]);
        self::assertArrayHasKey('APP_ADMIN_REMEMBER_ME', $this->cookies, 'The Sylius remember me cookie must be set by the login.');

        // The browser comes back with its remember me cookie only.
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM iq2i_herse_trusted_device');
        unset($this->cookies['MOCKSESSID'], $this->cookies['herse_device_admin']);
        $this->resetMails();

        $response = $this->send('GET', '/admin/private');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->devices());
        self::assertSame([], $this->mails());
    }
}
