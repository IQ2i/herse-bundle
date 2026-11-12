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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IQ2i\HerseBundle\Event\PasswordChangedEvent;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;
use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use IQ2i\HerseBundle\Tests\TestApplication\InMemoryLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Boots the test application with an in-memory database holding the Account table.
 */
abstract class PasswordChangeKernelTestCase extends HerseKernelTestCase
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

        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Account::class)]);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
    }

    protected function logger(): InMemoryLogger
    {
        return $this->service(InMemoryLogger::class, 'logger');
    }

    /**
     * Stores an account without any notification: its creation is not a password change.
     */
    protected function createAccount(string $email = 'alice@example.com', ?string $password = 'old-hash', ?string $locale = null): Account
    {
        $account = new Account($email, $password, $locale);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();
        $this->resetMails();

        return $account;
    }

    protected function changePassword(Account $account, string $password = 'new-hash'): void
    {
        $account->setPassword($password);
        $this->entityManager()->flush();
    }

    protected function resetMails(): void
    {
        $this->service(MessageLoggerListener::class, 'mailer.message_logger_listener')->reset();
    }

    /**
     * @return list<TemplatedEmail>
     */
    protected function mails(): array
    {
        $mails = [];
        foreach ($this->service(MessageLoggerListener::class, 'mailer.message_logger_listener')->getEvents()->getMessages() as $message) {
            self::assertInstanceOf(TemplatedEmail::class, $message);
            $mails[] = $message;
        }

        return $mails;
    }

    protected function simulateWebRequest(?UserInterface $authenticated = null): void
    {
        $this->service(RequestStack::class, 'request_stack')->push(Request::create('/'));
        if (null !== $authenticated) {
            $this->service(TokenStorageInterface::class, 'security.token_storage')->setToken(new UsernamePasswordToken($authenticated, 'main', $authenticated->getRoles()));
        }
    }

    protected function contextOfTheLastEvent(): PasswordChangeContext
    {
        $event = end($this->events);
        self::assertInstanceOf(PasswordChangedEvent::class, $event);

        return $event->context;
    }

    protected function login(string $email, string $password): int
    {
        return $this->handle(Request::create('/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => $password], \JSON_THROW_ON_ERROR)));
    }

    protected function resetPassword(string $email, string $password): int
    {
        return $this->handle(Request::create('/reset', 'POST', ['email' => $email, 'password' => $password]));
    }

    private function handle(Request $request): int
    {
        return $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false)->getStatusCode();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class, string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
