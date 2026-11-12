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

use IQ2i\HerseBundle\Event\PasswordChangedEvent;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;
use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Boots the test application with an in-memory database holding the Account table.
 */
abstract class PasswordChangeKernelTestCase extends DoctrineKernelTestCase
{
    /** @var list<PasswordChangedEvent> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PasswordChangedEvent::class, function (PasswordChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    protected function changePassword(Account $account, string $password = 'new-hash'): void
    {
        $account->setPassword($password);
        $this->entityManager()->flush();
    }

    protected function simulateWebRequest(?UserInterface $authenticated = null): void
    {
        $this->service(RequestStack::class, 'request_stack')->push(Request::create('/'));
        if (null !== $authenticated) {
            $this->service(TokenStorageInterface::class, 'security.token_storage')->setToken(new UsernamePasswordToken($authenticated, 'main', $authenticated->getRoles()));
        }
    }

    protected function resetPassword(string $email, string $password): int
    {
        return $this->handle(Request::create('/reset', 'POST', ['email' => $email, 'password' => $password]))->getStatusCode();
    }

    protected function contextOfTheLastEvent(): PasswordChangeContext
    {
        $event = end($this->events);
        self::assertInstanceOf(PasswordChangedEvent::class, $event);

        return $event->context;
    }
}
