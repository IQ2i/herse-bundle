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

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Event\NewDeviceEvent;
use Moka\Cms\Entity\User\AbstractUser;
use Moka\Cms\Security\ResetTokenHelper;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Boots the Moka application with an in-memory database, and plays one browser: the cookies are kept between the requests.
 *
 * The screens are the ones of Moka: the login goes through its form, its CSRF token and its own authenticator.
 */
abstract class MokaKernelTestCase extends TestCase
{
    protected const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0';
    protected const PASSWORD = 'Sup3r-secret!';
    protected const USER_CLASS = 'Moka\Cms\GeneratedEntity\User\User';

    protected Kernel $kernel;

    /** @var array<string, string> */
    protected array $cookies = [];

    /** @var list<NewDeviceEvent> */
    protected array $deviceEvents = [];

    /** @var list<TemplatedEmail> */
    private array $sentMails = [];

    /** @var callable|null */
    private $handlerBeforeBoot;

    protected static function environment(): string
    {
        return 'test';
    }

    protected function setUp(): void
    {
        $this->handlerBeforeBoot = $this->currentExceptionHandler();
        $this->kernel = new Kernel(static::environment(), true);
        $this->kernel->boot();

        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The counters of the login throttling live in the cache of the process: no test may see the attempts of another one.
        $this->service(CacheItemPoolInterface::class, 'test.herse_rate_limiter.public')->clear();

        $this->cookies = [];
        $this->sentMails = [];
        $this->deviceEvents = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(NewDeviceEvent::class, function (NewDeviceEvent $event): void {
            $this->deviceEvents[] = $event;
        });
    }

    protected function tearDown(): void
    {
        if (!isset($this->kernel)) {
            return;
        }

        $this->kernel->shutdown();

        // Booting a debug kernel registers an exception handler on some Symfony versions, which PHPUnit reports as risky.
        while ($this->currentExceptionHandler() !== $this->handlerBeforeBoot) {
            restore_exception_handler();
        }
    }

    protected function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
    }

    /**
     * An administrator stored the way the fixtures of the Moka skeleton do. Without a password, the account is the one
     * Moka creates from its user screen: the password comes later, from the link of the welcome email.
     */
    protected function createAdmin(string $email = 'admin@example.com', ?string $password = self::PASSWORD, bool $secondFactor = true): AbstractUser
    {
        $class = self::USER_CLASS;
        $admin = new $class();
        self::assertInstanceOf(AbstractUser::class, $admin);
        $admin
            ->setEmail($email)
            ->setFirstName('Admin')
            ->setLastName('Moka')
            ->setLocale('en_US')
            ->setRole('ROLE_ADMIN');
        if (null !== $password) {
            $admin->setPassword($this->service(UserPasswordHasherInterface::class, 'security.user_password_hasher')->hashPassword($admin, $password));
        }
        if (method_exists($admin, 'setEmailAuthEnabled')) {
            $admin->setEmailAuthEnabled($secondFactor);
        }

        $this->entityManager()->persist($admin);
        $this->entityManager()->flush();
        $this->resetMails();

        return $admin;
    }

    protected function reload(AbstractUser $user): AbstractUser
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(self::USER_CLASS, $user->getId());
        self::assertInstanceOf(AbstractUser::class, $reloaded);

        return $reloaded;
    }

    /**
     * Fills the login form of Moka: its fields are "email" and "password", protected by a CSRF token.
     */
    protected function login(string $email = 'admin@example.com', string $password = self::PASSWORD, string $ip = '203.0.113.7'): Response
    {
        $form = $this->send('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        self::assertSame(200, $form->getStatusCode());

        return $this->send('POST', '/admin/login', ['email' => $email, 'password' => $password, '_csrf_token' => $this->hiddenField($form, '_csrf_token')], ['REMOTE_ADDR' => $ip]);
    }

    /**
     * Resets the password through the screen of Moka, with a link like the one of its emails.
     */
    protected function resetPassword(AbstractUser $user, string $password, ?string $confirmation = null): Response
    {
        $user = $this->reload($user);
        $token = $this->service(ResetTokenHelper::class, ResetTokenHelper::class)->createToken($user);
        $this->entityManager()->flush();

        $path = '/admin/reset-password/'.$user->getId().'?token='.$token;
        $form = $this->send('GET', $path);
        self::assertSame(200, $form->getStatusCode());

        return $this->send('POST', $path, ['reset_password' => [
            'password' => ['first' => $password, 'second' => $confirmation ?? $password],
            '_token' => $this->hiddenField($form, 'reset_password[_token]'),
        ]]);
    }

    protected function hiddenField(Response $response, string $name): string
    {
        self::assertSame(1, preg_match('/<input[^>]*name="'.preg_quote($name, '/').'"[^>]*value="([^"]*)"/', (string) $response->getContent(), $matches), \sprintf('The "%s" field was expected.', $name));

        return html_entity_decode($matches[1]);
    }

    protected function assertRedirectedTo(string $path, Response $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($path, parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
    }

    /**
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $server
     */
    protected function send(string $method, string $path, array $parameters = [], array $server = []): Response
    {
        $server += ['HTTP_USER_AGENT' => self::FIREFOX, 'REMOTE_ADDR' => '203.0.113.7'];

        // Caught: the access denied exceptions are what the firewalls turn into a redirect.
        $response = $this->kernel->handle(Request::create($path, $method, $parameters, $this->cookies, [], $server), HttpKernelInterface::MAIN_REQUEST, true);
        $this->sentMails = [...$this->sentMails, ...$this->loggedMails()];
        $this->logger()->reset();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared() || null === $cookie->getValue()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * The emails of the whole scenario: the mailer forgets them when the next request starts.
     *
     * @return list<TemplatedEmail>
     */
    protected function mails(): array
    {
        return [...$this->sentMails, ...$this->loggedMails()];
    }

    /**
     * @return list<string>
     */
    protected function mailSubjects(): array
    {
        return array_map(static fn (TemplatedEmail $mail): string => (string) $mail->getSubject(), $this->mails());
    }

    protected function resetMails(): void
    {
        $this->sentMails = [];
        $this->logger()->reset();
    }

    /**
     * What the database holds, read afresh.
     *
     * @return list<TrustedDevice>
     */
    protected function devices(): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(TrustedDevice::class)->findBy([], ['id' => 'ASC']);
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

    private function logger(): MessageLoggerListener
    {
        return $this->service(MessageLoggerListener::class, 'mailer.message_logger_listener');
    }

    /**
     * @return list<TemplatedEmail>
     */
    private function loggedMails(): array
    {
        $mails = [];
        foreach ($this->logger()->getEvents()->getMessages() as $message) {
            self::assertInstanceOf(TemplatedEmail::class, $message);
            $mails[] = $message;
        }

        return $mails;
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
