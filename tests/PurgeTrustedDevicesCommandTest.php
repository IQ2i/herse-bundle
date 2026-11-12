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

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeTrustedDevicesCommandTest extends TrustedDevicesKernelTestCase
{
    public function testTheExpiredDevicesAreDeleted(): void
    {
        $this->expireOneDeviceOutOfTwo();

        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 expired trusted device(s) deleted.', $tester->getDisplay());
        self::assertSame(['Chrome (Windows)'], array_map(static fn ($device): ?string => $device->getLabel(), $this->devices()));
    }

    public function testADryRunDeletesNothing(): void
    {
        $this->expireOneDeviceOutOfTwo();

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('[Dry run] 1 expired trusted device(s) would be deleted.', $tester->getDisplay());
        self::assertCount(2, $this->devices());
    }

    private function expireOneDeviceOutOfTwo(): void
    {
        $this->account();
        $this->signIn();
        $this->signIn(userAgent: self::CHROME);
        $this->entityManager()->getConnection()->executeStatement("UPDATE iq2i_herse_trusted_device SET expires_at = '2000-01-01 00:00:00' WHERE id = 1");
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application($this->kernel))->find('iq2i:herse:purge-trusted-devices'));
    }
}
