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

use IQ2i\HerseBundle\TrustedDevice\TrustLevel;

/**
 * The devices are registered by the login form of Moka, and managed from a screen added to its administration.
 */
final class TrustedDevicesTest extends MokaKernelTestCase
{
    public function testAnUnknownDeviceIsRegisteredAndTheAdministratorIsWarned(): void
    {
        $admin = $this->createAdmin();

        $this->assertRedirectedTo('/admin/', $this->login());

        self::assertArrayHasKey('herse_device_moka', $this->cookies);
        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame('admin', $devices[0]->getUserType());
        self::assertSame((string) $admin->getId(), $devices[0]->getUserIdentifier());
        self::assertSame('moka', $devices[0]->getFirewall());
        self::assertSame(TrustLevel::Known, $devices[0]->getTrustLevel());
        self::assertSame('Firefox (macOS)', $devices[0]->getLabel());

        self::assertSame(['New sign-in to your account'], $this->mailSubjects());
        self::assertSame('admin@example.com', $this->mails()[0]->getTo()[0]->getAddress());
    }

    public function testAKnownDeviceIsNotWarnedAgain(): void
    {
        $this->createAdmin();
        $this->login();
        $this->resetMails();
        unset($this->cookies['MOCKSESSID']);

        $this->assertRedirectedTo('/admin/', $this->login(ip: '198.51.100.9'));

        self::assertSame([], $this->mails());
        self::assertCount(1, $this->devices());
    }

    public function testTheAdministrationOfMokaListsTheDevices(): void
    {
        $this->createAdmin();
        $this->login();

        $dashboard = $this->send('GET', '/admin/');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('/admin/devices', (string) $dashboard->getContent(), 'The screen is in the menu of Moka.');

        $screen = $this->send('GET', '/admin/devices');
        self::assertSame(200, $screen->getStatusCode());
        self::assertStringContainsString('Firefox (macOS)', (string) $screen->getContent());
        self::assertStringContainsString('This device', (string) $screen->getContent());
    }

    public function testADeviceRevokedFromTheAdministrationIsUnknownAgain(): void
    {
        $this->createAdmin();
        $this->login();
        $device = $this->devices()[0];
        $screen = $this->send('GET', '/admin/devices');

        $this->assertRedirectedTo('/admin/devices', $this->send('POST', '/admin/devices/'.$device->getId().'/revoke', ['_token' => $this->hiddenField($screen, '_token')]));

        self::assertSame([], $this->devices());
        $this->resetMails();
        unset($this->cookies['MOCKSESSID']);
        $this->login();
        self::assertSame(['New sign-in to your account'], $this->mailSubjects());
    }

    public function testTheScreenIsReservedToTheAdministrators(): void
    {
        $this->assertRedirectedTo('/admin/login', $this->send('GET', '/admin/devices'));
    }
}
