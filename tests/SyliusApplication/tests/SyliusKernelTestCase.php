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

use App\Entity\Channel\Channel;
use App\Entity\Currency\Currency;
use App\Entity\Customer\Customer;
use App\Entity\Locale\Locale;
use App\Entity\User\AdminUser;
use App\Entity\User\ShopUser;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Event\NewDeviceEvent;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;

/**
 * Boots the Sylius application with an in-memory database, and plays one browser: the cookies are kept between the requests.
 */
abstract class SyliusKernelTestCase extends TestCase
{
    protected const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0';
    protected const PASSWORD = 'Sup3r-secret!';

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
        $this->createChannel();

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
     * The shop user checker of Sylius resolves the channel of the request, found here by its host name.
     */
    private function createChannel(): void
    {
        $locale = new Locale();
        $locale->setCode('en_US');
        $currency = new Currency();
        $currency->setCode('USD');

        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setName('Web');
        $channel->setHostname('localhost');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->setTaxCalculationStrategy('order_items_based');
        $channel->setAccountVerificationRequired(false);

        foreach ([$locale, $currency, $channel] as $entity) {
            $this->entityManager()->persist($entity);
        }
        $this->entityManager()->flush();
    }

    /**
     * An administrator that is not stored yet.
     */
    protected function newAdmin(string $email = 'admin@example.com', string $password = self::PASSWORD): AdminUser
    {
        $admin = new AdminUser();
        $admin->setEmail($email);
        $admin->setUsername($email);
        $admin->setLocaleCode('en_US');
        $admin->setEnabled(true);
        $admin->addRole('ROLE_ADMINISTRATION_ACCESS');
        $admin->setPlainPassword($password);
        $admin->enableEmailAuth();

        return $admin;
    }

    /**
     * A shop user and its customer that are not stored yet.
     */
    protected function newShopUser(string $email = 'customer@example.com', string $password = self::PASSWORD): ShopUser
    {
        $customer = new Customer();
        $customer->setEmail($email);
        $customer->setEmailCanonical($email);

        $user = new ShopUser();
        $user->setUsername($email);
        $user->setEnabled(true);
        $user->setPlainPassword($password);
        $customer->setUser($user);

        return $user;
    }

    /**
     * An administrator created the way Sylius does: the plain password is hashed when the entity is stored.
     */
    protected function createAdmin(string $email = 'admin@example.com', string $password = self::PASSWORD): AdminUser
    {
        $admin = $this->newAdmin($email, $password);
        $this->entityManager()->persist($admin);
        $this->entityManager()->flush();
        $this->resetMails();

        return $admin;
    }

    /**
     * A customer with a shop user, created the way Sylius does: the plain password is hashed when the entity is stored.
     */
    protected function createShopUser(string $email = 'customer@example.com', string $password = self::PASSWORD): ShopUser
    {
        $user = $this->newShopUser($email, $password);
        $this->entityManager()->persist($user->getCustomer());
        $this->entityManager()->flush();
        $this->resetMails();

        return $user;
    }

    protected function loginAsAdmin(string $email = 'admin@example.com', string $password = self::PASSWORD, string $ip = '203.0.113.7'): Response
    {
        return $this->send('POST', '/admin/login-check', json: ['_username' => $email, '_password' => $password], server: ['REMOTE_ADDR' => $ip]);
    }

    protected function loginAsCustomer(string $email = 'customer@example.com', string $password = self::PASSWORD, string $ip = '203.0.113.7'): Response
    {
        return $this->send('POST', '/login-check', json: ['_username' => $email, '_password' => $password], server: ['REMOTE_ADDR' => $ip]);
    }

    /**
     * @param array<string, string>     $parameters
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $server
     */
    protected function send(string $method, string $path, array $parameters = [], ?array $json = null, array $server = []): Response
    {
        $server += ['HTTP_USER_AGENT' => self::FIREFOX, 'REMOTE_ADDR' => '203.0.113.7'];
        $content = null;
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        // Caught: the access denied exceptions are what the second factor firewall turns into a redirect.
        $response = $this->kernel->handle(Request::create($path, $method, $parameters, $this->cookies, [], $server, $content), HttpKernelInterface::MAIN_REQUEST, true);
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
