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

use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Event\NewDeviceEvent;
use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots the trusted devices application: the "main" and "admin" firewalls are watched, "public" is not.
 */
abstract class TrustedDevicesKernelTestCase extends DoctrineKernelTestCase
{
    protected const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0';
    protected const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /** @var list<NewDeviceEvent> */
    protected array $events = [];

    protected static function environment(): string
    {
        return 'trusted_devices';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(NewDeviceEvent::class, function (NewDeviceEvent $event): void {
            $this->events[] = $event;
        });
    }

    /**
     * An account whose password is "secret".
     */
    protected function account(string $email = 'alice@example.com', ?string $locale = null): Account
    {
        return $this->createAccount($email, password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]), $locale);
    }

    /**
     * @param array<string, string> $cookies
     */
    protected function signIn(string $email = 'alice@example.com', string $path = '/login', array $cookies = [], string $userAgent = self::FIREFOX, string $ip = '203.0.113.7'): Response
    {
        $response = $this->loginResponse($email, 'secret', $path, $cookies, ['HTTP_USER_AGENT' => $userAgent, 'REMOTE_ADDR' => $ip]);
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    protected function cookie(Response $response, string $name = 'herse_device_main'): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * The token of the device cookie of the response.
     */
    protected function token(Response $response, string $name = 'herse_device_main'): string
    {
        $cookie = $this->cookie($response, $name);
        self::assertNotNull($cookie, \sprintf('The response must set the "%s" cookie.', $name));
        self::assertNotNull($cookie->getValue());

        return $cookie->getValue();
    }

    /**
     * What the database holds, read afresh.
     *
     * @return list<TrustedDevice>
     *
     * @phpstan-impure
     */
    protected function devices(): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(TrustedDevice::class)->findBy([], ['id' => 'ASC']);
    }
}
