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

namespace IQ2i\HerseBundle\Tests\Mailer;

use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\Tests\TestApplication\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Translation\IdentityTranslator;

final class MailerTest extends TestCase
{
    public function testAnInvalidSenderIsReportedAsSuch(): void
    {
        $logger = new InMemoryLogger();
        $mailer = new Mailer($this->createStub(MailerInterface::class), new IdentityTranslator(), $logger, 'not an address', null, null);

        self::assertNull($mailer->create('@IQ2iHerse/email/password_changed.html.twig', 'Your password has been changed', 'alice@example.com', null));
        self::assertSame(['iq2i_herse.mailer.sender is not a valid email address, the "{subject}" email is not sent.'], $logger->messages('error'));
        self::assertSame([], $logger->messages('warning'), 'The recipient is not blamed.');
    }
}
