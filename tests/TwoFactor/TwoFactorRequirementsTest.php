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

namespace IQ2i\HerseBundle\Tests\TwoFactor;

use IQ2i\HerseBundle\Tests\TestApplication\Kernel;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * The container refuses the feature with the Composer command to run, or the registration to add.
 */
final class TwoFactorRequirementsTest extends TestCase
{
    public function testTheContainerDoesNotCompileWhileSchebIsNotInstalled(): void
    {
        if (class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('Only meaningful while scheb is not installed.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('iq2i_herse.two_factor requires scheb/2fa-bundle, scheb/2fa-email. Try running "composer require scheb/2fa-bundle scheb/2fa-email".');

        (new Kernel('two_factor_missing', true))->boot();
    }

    public function testTheContainerDoesNotCompileWhileTheSchebBundleIsNotRegistered(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires the bundle "Scheb\TwoFactorBundle\SchebTwoFactorBundle" to be registered in config/bundles.php');

        (new Kernel('two_factor_unregistered', true))->boot();
    }
}
