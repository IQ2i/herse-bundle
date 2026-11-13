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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * The checks run against what the real security extension builds, whatever the Symfony version.
 */
final class FirewallsVerificationTest extends TestCase
{
    public function testTheKernelRefusesToBootWhileADeclaredFirewallKeepsTheNativeLimiter(): void
    {
        $handlerBeforeBoot = $this->currentExceptionHandler();

        try {
            (new Kernel('firewalls_missing_limiter', true))->boot();
            self::fail('The kernel booted.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('The firewall "main" does not use the login limiter of Herse', $exception->getMessage());
        } finally {
            // Booting a debug kernel registers an exception handler on some Symfony versions, which PHPUnit reports as risky.
            while ($this->currentExceptionHandler() !== $handlerBeforeBoot) {
                restore_exception_handler();
            }
        }
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
