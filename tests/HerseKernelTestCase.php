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

use IQ2i\HerseBundle\Tests\TestApplication\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Boots the test application in the environment of the feature under test.
 */
abstract class HerseKernelTestCase extends TestCase
{
    protected Kernel $kernel;

    /** @var callable|null */
    private $handlerBeforeBoot;

    abstract protected static function environment(): string;

    protected function setUp(): void
    {
        $this->handlerBeforeBoot = $this->currentExceptionHandler();
        $this->kernel = new Kernel(static::environment(), true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        // A test skipped in its setUp, before the boot, has no kernel.
        if (!isset($this->kernel)) {
            return;
        }

        $this->kernel->shutdown();

        // Booting a debug kernel registers an exception handler on some Symfony versions, which PHPUnit reports as risky.
        while ($this->currentExceptionHandler() !== $this->handlerBeforeBoot) {
            restore_exception_handler();
        }
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
