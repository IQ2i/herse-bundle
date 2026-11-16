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

final class DeviceEntityMappedTest extends DeviceEntityAutoMappingKernelTestCase
{
    protected static function environment(): string
    {
        return 'trusted_devices_auto_mapping';
    }

    public function testTheDeviceEntityIsMappedOnceWhenTheFeatureIsEnabled(): void
    {
        self::assertCount(1, array_keys($this->mappedClasses(), TrustedDevice::class, true));
    }
}
