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
use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use IQ2i\HerseBundle\Tests\TestApplication\InMemoryLogger;
use IQ2i\HerseBundle\Tests\TestApplication\MailCollector;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Boots the test application with an in-memory database holding the tables of every mapped entity.
 */
abstract class DoctrineKernelTestCase extends HerseKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = $this->entityManager();
        // Rebuilt as a list: the return type of getAllMetadata() is only one from doctrine/persistence 4.
        $classes = [];
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $classes[] = $metadata;
        }
        (new SchemaTool($entityManager))->createSchema($classes);
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

    protected function resetMails(): void
    {
        $this->service(MailCollector::class, MailCollector::class)->reset();
    }

    /**
     * @return list<TemplatedEmail>
     */
    protected function mails(): array
    {
        $mails = [];
        foreach ($this->service(MailCollector::class, MailCollector::class)->messages() as $message) {
            self::assertInstanceOf(TemplatedEmail::class, $message);
            $mails[] = $message;
        }

        return $mails;
    }

    protected function login(string $email, string $password): int
    {
        return $this->loginResponse($email, $password)->getStatusCode();
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $server
     */
    protected function loginResponse(string $email, string $password, string $path = '/login', array $cookies = [], array $server = []): Response
    {
        return $this->handle(Request::create($path, 'POST', [], $cookies, [], $server + ['CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => $password], \JSON_THROW_ON_ERROR)));
    }

    protected function handle(Request $request): Response
    {
        return $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
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
