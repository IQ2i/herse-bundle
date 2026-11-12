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
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class PasswordPolicyKernelTestCase extends TestCase
{
    protected Kernel $kernel;

    /** @var callable|null */
    private $handlerBeforeBoot;

    protected function setUp(): void
    {
        $this->handlerBeforeBoot = $this->currentExceptionHandler();
        $this->kernel = new Kernel('password_policy', true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();

        // Booting a debug kernel registers an exception handler on some Symfony versions, which PHPUnit reports as risky.
        while ($this->currentExceptionHandler() !== $this->handlerBeforeBoot) {
            restore_exception_handler();
        }
    }

    protected function validator(): ValidatorInterface
    {
        /** @var ValidatorInterface $validator */
        $validator = $this->kernel->getContainer()->get('test.validator');

        return $validator;
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
