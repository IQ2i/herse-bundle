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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use IQ2i\HerseBundle\Event\PasswordChangedEvent;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification;
use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class PasswordChangeNotificationTest extends PasswordChangeKernelTestCase
{
    protected static function environment(): string
    {
        return 'password_change_notification';
    }

    public function testTheOwnerIsWarnedOfAPasswordChange(): void
    {
        $account = $this->createAccount();

        $this->changePassword($account);

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('alice@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('no-reply@example.com', $mails[0]->getFrom()[0]->getAddress());
        self::assertSame('Example', $mails[0]->getFrom()[0]->getName());
        self::assertSame('Your password has been changed', $mails[0]->getSubject());
        $body = $mails[0]->getHtmlBody();
        self::assertIsString($body);
        self::assertStringContainsString('The password of your account has just been changed.', $body);
        self::assertStringContainsString('Date and time of the change: '.date('Y-m-d H:i'), $body);
        self::assertStringContainsString('If you did not make this change', $body);
        self::assertStringNotContainsString('new-hash', $body);
        self::assertStringNotContainsString('href=', $body);
    }

    public function testTheProjectCanOverrideTheLayoutOfTheEmails(): void
    {
        $this->changePassword($this->createAccount());

        $body = $this->mails()[0]->getHtmlBody();
        self::assertIsString($body);
        self::assertStringContainsString('<header id="project-layout">Example shop</header>', $body);
        self::assertStringContainsString('The password of your account has just been changed.', $body);
    }

    public function testTheEmailFollowsTheLocaleOfTheUser(): void
    {
        $account = $this->createAccount(locale: 'fr');

        $this->changePassword($account);

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('Votre mot de passe a été modifié', $mails[0]->getSubject());
        self::assertStringContainsString('vient d&#039;être modifié', (string) $mails[0]->getHtmlBody());
    }

    public function testTheCreationOfAnAccountIsNotNotified(): void
    {
        $this->entityManager()->persist(new Account('bob@example.com', 'hash'));
        $this->entityManager()->flush();

        self::assertSame([], $this->mails());
    }

    public function testTheFirstPasswordOfAnAccountIsNotNotified(): void
    {
        $account = $this->createAccount(password: null);

        $this->changePassword($account);

        self::assertSame([], $this->mails());
    }

    public function testAnEmptyPasswordIsNotNotified(): void
    {
        $account = $this->createAccount();

        $account->setPassword(null);
        $this->entityManager()->flush();

        self::assertSame([], $this->mails());
    }

    public function testAnotherFieldChangingIsNotNotified(): void
    {
        $account = $this->createAccount();

        $account->setEmail('alice+new@example.com');
        $this->entityManager()->flush();

        self::assertSame([], $this->mails());
    }

    public function testTheAutomaticRehashDuringALoginIsNotNotified(): void
    {
        // Hashed with cost 4 while the hasher is configured with cost 5.
        $stale = password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 4]);
        $account = $this->createAccount(password: $stale);

        self::assertSame(200, $this->login('alice@example.com', 'secret'));

        $this->entityManager()->refresh($account);
        self::assertNotSame($stale, $account->getPassword(), 'The login must have rehashed the password, or this test proves nothing.');
        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
    }

    public function testAFailedLoginDoesNotLeaveTheDetectionDisabled(): void
    {
        $this->createAccount(password: password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $account = $this->createAccount('bob@example.com');

        self::assertSame(401, $this->login('alice@example.com', 'wrong'));
        $this->changePassword($account);

        self::assertCount(1, $this->mails());
    }

    public function testAPasswordResetFollowedByAnAutomaticLoginIsNotified(): void
    {
        $this->createAccount();

        self::assertSame(200, $this->resetPassword('alice@example.com', 'brand-new-secret'));

        self::assertCount(1, $this->mails());
        self::assertSame(PasswordChangeContext::Self, $this->contextOfTheLastEvent());
    }

    public function testTheNotificationCanBeSuspended(): void
    {
        $account = $this->createAccount();
        $notification = $this->service(PasswordChangeNotification::class, PasswordChangeNotification::class);

        $notification->withoutNotification(fn () => $this->changePassword($account, 'import-hash'));
        self::assertSame([], $this->mails());

        $notification->disable();
        $notification->disable();
        $notification->enable();
        $this->changePassword($account, 'still-suspended');
        self::assertSame([], $this->mails());

        $notification->enable();
        $notification->enable();
        $this->changePassword($account, 'back-on');
        self::assertCount(1, $this->mails());
    }

    public function testASuspensionDoesNotOutliveTheRequest(): void
    {
        $account = $this->createAccount();
        $notification = $this->service(PasswordChangeNotification::class, PasswordChangeNotification::class);
        $notification->disable();

        // What the kernel does between two requests or two messages of a long-running worker.
        $notification->reset();

        $this->changePassword($account);
        self::assertCount(1, $this->mails());
    }

    public function testTheNotificationIsResumedWhenTheSuspendedCodeThrows(): void
    {
        $account = $this->createAccount();
        $notification = $this->service(PasswordChangeNotification::class, PasswordChangeNotification::class);

        try {
            $notification->withoutNotification(static function (): void {
                throw new \RuntimeException('import failed');
            });
        } catch (\RuntimeException) {
        }

        $this->changePassword($account);
        self::assertCount(1, $this->mails());
    }

    public function testNothingIsSentWhenTheFlushFails(): void
    {
        $account = $this->createAccount();
        $this->createAccount('bob@example.com');

        $account->setPassword('new-hash');
        $account->setEmail('bob@example.com');

        try {
            $this->entityManager()->flush();
            self::fail('The duplicated email must make the flush fail.');
        } catch (UniqueConstraintViolationException) {
        }
        $this->entityManager()->clear();

        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
    }

    public function testTheContextIsTheConsoleOutsideOfAWebRequest(): void
    {
        $this->changePassword($this->createAccount());

        self::assertSame(PasswordChangeContext::Console, $this->contextOfTheLastEvent());
    }

    public function testTheContextIsSelfWhenNobodyIsAuthenticated(): void
    {
        $account = $this->createAccount();
        $this->simulateWebRequest();

        $this->changePassword($account);

        self::assertSame(PasswordChangeContext::Self, $this->contextOfTheLastEvent());
    }

    public function testTheContextIsSelfWhenTheOwnerIsAuthenticated(): void
    {
        $account = $this->createAccount();
        $this->simulateWebRequest($account);

        $this->changePassword($account);

        self::assertSame(PasswordChangeContext::Self, $this->contextOfTheLastEvent());
    }

    public function testTheContextIsOtherUserWhenAnotherDeclaredUserIsAuthenticated(): void
    {
        $account = $this->createAccount();
        $admin = $this->createAccount('admin@example.com');
        $this->simulateWebRequest($admin);

        $this->changePassword($account);

        self::assertSame(PasswordChangeContext::OtherUser, $this->contextOfTheLastEvent());
        self::assertCount(1, $this->mails());
    }

    public function testTheContextIsOtherUserWhenTheOwnerIsImpersonated(): void
    {
        $account = $this->createAccount();
        $admin = new InMemoryUser('root', null, ['ROLE_ADMIN']);
        $this->simulateWebRequest();
        $this->service(TokenStorageInterface::class, 'security.token_storage')->setToken(
            new SwitchUserToken($account, 'main', $account->getRoles(), new UsernamePasswordToken($admin, 'main', $admin->getRoles())),
        );

        $this->changePassword($account);

        self::assertSame(PasswordChangeContext::OtherUser, $this->contextOfTheLastEvent());
    }

    public function testTheContextIsOtherUserWhenAnUndeclaredUserIsAuthenticated(): void
    {
        $account = $this->createAccount();
        $this->simulateWebRequest(new InMemoryUser('root', null, ['ROLE_ADMIN']));

        $this->changePassword($account);

        self::assertSame(PasswordChangeContext::OtherUser, $this->contextOfTheLastEvent());
    }

    public function testTheEventDescribesTheChange(): void
    {
        $account = $this->createAccount();
        $before = new \DateTimeImmutable();

        $this->changePassword($account);

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertSame($account, $event->user);
        self::assertSame('customer', $event->reference->type);
        self::assertSame((string) $account->getId(), $event->reference->identifier);
        self::assertGreaterThanOrEqual($before, $event->changedAt);
        self::assertSame('alice@example.com', $event->getEmail()->getTo()[0]->getAddress());
    }

    public function testAListenerCanCancelTheEmail(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PasswordChangedEvent::class, static function (PasswordChangedEvent $event): void {
            $event->cancel();
        });

        $this->changePassword($this->createAccount());

        self::assertCount(1, $this->events);
        self::assertSame([], $this->mails());
    }

    public function testAListenerCanReplaceTheEmail(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PasswordChangedEvent::class, static function (PasswordChangedEvent $event): void {
            $event->setEmail((new TemplatedEmail())->from('support@example.com')->to('alice@example.com')->subject('Custom subject')->text('Custom body'));
        });

        $this->changePassword($this->createAccount());

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('Custom subject', $mails[0]->getSubject());
        self::assertSame('Custom body', $mails[0]->getTextBody());
    }

    public function testNothingIsSentWithoutAnEmailAddress(): void
    {
        $this->changePassword($this->createAccount(''));

        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
        self::assertStringContainsString('No email address is available', $this->logger()->messages('warning')[0]);
    }

    public function testNothingIsSentToAnInvalidEmailAddress(): void
    {
        $this->changePassword($this->createAccount('not an address'));

        self::assertSame([], $this->mails());
        self::assertStringContainsString('email address is invalid', $this->logger()->messages('warning')[0]);
    }

    public function testATransportFailureNeverFailsThePasswordChange(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(MessageEvent::class, static function (): void {
            throw new TransportException('The SMTP server is down.');
        });
        $account = $this->createAccount();

        $this->changePassword($account);

        $this->entityManager()->refresh($account);
        self::assertSame('new-hash', $account->getPassword());
        self::assertSame(['The security email could not be sent.'], $this->logger()->messages('error'));
    }

    public function testAFailingListenerNeverFailsThePasswordChange(): void
    {
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PasswordChangedEvent::class, static function (): void {
            throw new \RuntimeException('Broken listener.');
        });
        $account = $this->createAccount();

        $this->changePassword($account);

        $this->entityManager()->refresh($account);
        self::assertSame('new-hash', $account->getPassword());
        self::assertSame(['The password change notification failed.'], $this->logger()->messages('error'));
    }
}
