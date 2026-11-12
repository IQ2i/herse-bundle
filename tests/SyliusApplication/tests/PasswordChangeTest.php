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

namespace App\Tests;

use App\Entity\User\ShopUser;
use IQ2i\HerseBundle\Event\PasswordChangedEvent;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;
use Sylius\Component\User\Security\PasswordUpdaterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Sylius hashes the password with its own updater, and the change is detected on the hashed column.
 */
final class PasswordChangeTest extends SyliusKernelTestCase
{
    private const NEW_PASSWORD = 'An0ther-secret!';

    /** @var list<PasswordChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PasswordChangedEvent::class, function (PasswordChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheCreationOfAnAccountSendsNothing(): void
    {
        $user = $this->newShopUser();
        $this->entityManager()->persist($user->getCustomer());
        $this->entityManager()->flush();
        $admin = $this->newAdmin();
        $this->entityManager()->persist($admin);
        $this->entityManager()->flush();

        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
    }

    public function testACustomerWhoChangesTheirPasswordIsNotified(): void
    {
        $user = $this->createShopUser();
        $this->simulateWebRequest($user);

        $this->changePassword($user);

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('customer@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('Your password has been changed', $mails[0]->getSubject());
        self::assertSame(PasswordChangeContext::Self, $this->events[0]->context);
        self::assertSame('shop', $this->events[0]->reference->type);
    }

    public function testAnAdministratorWhoChangesTheirPasswordIsNotified(): void
    {
        $admin = $this->createAdmin();
        $this->simulateWebRequest($admin);

        $this->changePassword($admin);

        self::assertCount(1, $this->mails());
        self::assertSame(PasswordChangeContext::Self, $this->events[0]->context);
        self::assertSame('admin', $this->events[0]->reference->type);
    }

    public function testAnAdministratorWhoChangesThePasswordOfACustomerNotifiesTheCustomer(): void
    {
        $user = $this->createShopUser();
        $admin = $this->createAdmin();
        $this->simulateWebRequest($admin);

        $this->changePassword($user);

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('customer@example.com', $mails[0]->getTo()[0]->getAddress());
        self::assertSame(PasswordChangeContext::OtherUser, $this->events[0]->context);
    }

    public function testAChangeOutsideOfAWebRequestIsNotifiedAsAConsoleChange(): void
    {
        $user = $this->createShopUser();

        $this->changePassword($user);

        self::assertCount(1, $this->mails());
        self::assertSame(PasswordChangeContext::Console, $this->events[0]->context);
    }

    /**
     * Sylius never rehashes a password at login: its user providers do not upgrade passwords. Whatever a project adds, the
     * authentication window of the bundle keeps such a write from being reported, which the main test suite proves.
     */
    public function testALoginWithAnOutdatedHashSendsNoPasswordChangeEmail(): void
    {
        $user = $this->newShopUser();
        $user->setPlainPassword(null);
        $user->setPassword(password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]));
        $this->entityManager()->persist($user->getCustomer());
        $this->entityManager()->flush();
        $this->resetMails();

        self::assertSame(200, $this->loginAsCustomer()->getStatusCode());

        $subjects = array_map(static fn ($mail): ?string => $mail->getSubject(), $this->mails());
        self::assertSame(['New sign-in to your account'], $subjects);
        self::assertSame([], $this->events);
    }

    private function changePassword(ShopUser|UserInterface $user): void
    {
        \assert($user instanceof \Sylius\Component\User\Model\UserInterface);
        $user->setPlainPassword(self::NEW_PASSWORD);
        // What the Sylius controllers do through their resource events, before the flush.
        $this->service(PasswordUpdaterInterface::class, 'sylius.security.password_updater')->updatePassword($user);
        $this->entityManager()->flush();
    }

    private function simulateWebRequest(UserInterface $authenticated): void
    {
        $this->service(RequestStack::class, 'request_stack')->push(Request::create('/'));
        $this->service(TokenStorageInterface::class, 'security.token_storage')->setToken(new UsernamePasswordToken($authenticated, 'main', $authenticated->getRoles()));
    }
}
