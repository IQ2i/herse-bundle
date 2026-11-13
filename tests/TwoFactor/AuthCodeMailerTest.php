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

use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\Tests\TestApplication\InMemoryLogger;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TwoFactorAccount;
use IQ2i\HerseBundle\TwoFactor\AuthCodeMailer;
use IQ2i\HerseBundle\User\UserResolver;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Translation\Translator;

final class AuthCodeMailerTest extends TestCase
{
    /** @var \ArrayObject<int, RawMessage> */
    private \ArrayObject $sent;

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }

        $this->sent = new \ArrayObject();
        $this->logger = new InMemoryLogger();
    }

    public function testTheCodeIsSentToTheRecipientOfTheUser(): void
    {
        $account = new TwoFactorAccount('alice@example.com');
        $account->setEmailAuthCode('123456');

        $this->mailer()->sendAuthCode($account);

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('123456', $email->getContext()['code']);
        self::assertSame(AuthCodeMailer::TEMPLATE, $email->getHtmlTemplate());
        self::assertSame([], $this->logger->records);
    }

    public function testAUserNamingNoRecipientGetsTheAddressDeclaredForItsClass(): void
    {
        $account = new EmailAccount('alice@example.com');
        $account->setEmailAuthCode('123456');
        self::assertSame('', $account->getEmailAuthRecipient());
        self::assertTrue($account->isEmailAuthEnabled());
        self::assertSame('123456', $account->getEmailAuthCode());

        $this->mailer([EmailAccount::class => ['type' => 'customer', 'email' => 'email', 'id' => 'id']])->sendAuthCode($account);

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
    }

    public function testNothingIsSentToAUserNamingNoRecipientWhoseClassIsNotDeclared(): void
    {
        $account = new EmailAccount('alice@example.com');
        $account->setEmailAuthCode('123456');

        $this->mailer()->sendAuthCode($account);

        self::assertCount(0, $this->sent);
    }

    public function testNothingIsSentWithoutACode(): void
    {
        $this->mailer()->sendAuthCode(new TwoFactorAccount('alice@example.com'));

        self::assertCount(0, $this->sent);
        self::assertSame(['No two-factor code is available for the user, the email is not sent.'], $this->logger->messages('warning'));
    }

    public function testNothingIsSentWithoutARecipient(): void
    {
        $account = new TwoFactorAccount('');
        $account->setEmailAuthCode('123456');

        $this->mailer()->sendAuthCode($account);

        self::assertCount(0, $this->sent);
        self::assertCount(1, $this->logger->messages('warning'));
    }

    public function testTheCodeIsNeverLogged(): void
    {
        $account = new TwoFactorAccount('not an address');
        $account->setEmailAuthCode('987654');

        $this->mailer()->sendAuthCode($account);

        self::assertStringNotContainsString('987654', json_encode($this->logger->records, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<class-string, array{type: string, email: string, id: string}> $users the declared user classes
     */
    private function mailer(array $users = []): AuthCodeMailer
    {
        $transport = new class($this->sent) implements MailerInterface {
            /**
             * @param \ArrayObject<int, RawMessage> $sent
             */
            public function __construct(private readonly \ArrayObject $sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent->append($message);
            }
        };

        return new AuthCodeMailer(
            new UserResolver($users, PropertyAccess::createPropertyAccessor()),
            new Mailer($transport, new Translator('en'), $this->logger, 'no-reply@example.com', null, null),
            $this->logger,
        );
    }
}
