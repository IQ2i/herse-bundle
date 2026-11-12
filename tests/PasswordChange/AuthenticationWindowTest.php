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

namespace IQ2i\HerseBundle\Tests\PasswordChange;

use IQ2i\HerseBundle\PasswordChange\AuthenticationWindow;
use PHPUnit\Framework\TestCase;

final class AuthenticationWindowTest extends TestCase
{
    public function testTheWindowIsClosedByDefaultAndCanBeOpenedAndClosed(): void
    {
        $window = new AuthenticationWindow();
        self::assertFalse($window->isOpen());

        $window->open();
        self::assertTrue($window->isOpen());

        $window->close();
        self::assertFalse($window->isOpen());
    }

    public function testAWindowLeftOpenIsClosedBetweenTwoRequests(): void
    {
        $window = new AuthenticationWindow();
        $window->open();

        $window->reset();

        self::assertFalse($window->isOpen());
    }
}
