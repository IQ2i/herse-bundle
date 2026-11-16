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

use IQ2i\HerseBundle\TrustedDevice\Entity\TrustedDevice;

final class DeviceEntityNotMappedTest extends DeviceEntityAutoMappingKernelTestCase
{
    protected static function environment(): string
    {
        return 'password_change_notification_auto_mapping';
    }

    public function testTheDeviceEntityIsNotMappedWhileTheFeatureIsDisabled(): void
    {
        self::assertNotContains(TrustedDevice::class, $this->mappedClasses());
    }
}
