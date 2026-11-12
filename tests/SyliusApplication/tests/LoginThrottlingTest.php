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

use IQ2i\HerseBundle\Event\LoginThrottledEvent;
use IQ2i\HerseBundle\RateLimiter\LoginThrottlingLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The thresholds of the application are 3 attempts for an identifier and an IP, 5 for an IP and 4 for an identifier.
 */
final class LoginThrottlingTest extends SyliusKernelTestCase
{
    /** @var list<LoginThrottledEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(LoginThrottledEvent::class, function (LoginThrottledEvent $event): void {
            $this->events[] = $event;
        });
        $this->createAdmin();
        $this->createShopUser('admin@example.com');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function firewalls(): iterable
    {
        yield 'admin' => ['admin', '/admin/login-check'];
        yield 'shop' => ['main shop', '/login-check'];
    }

    #[DataProvider('firewalls')]
    public function testAnIdentifierIsBlockedFromAnIpAfterTheThreshold(string $firewall, string $path): void
    {
        $firewall = $this->firewall($firewall);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame(401, $this->attempt($path, 'admin@example.com', 'wrong', '10.0.0.1')->getStatusCode());
        }

        self::assertSame([LoginThrottlingLimit::IdentifierAndIp], $this->limitsReached());
        self::assertSame($firewall, $this->events[0]->firewall);
        // The attempt is rejected before the right credentials are checked.
        self::assertSame(401, $this->attempt($path, 'admin@example.com', self::PASSWORD, '10.0.0.1')->getStatusCode());
    }

    #[DataProvider('firewalls')]
    public function testAnIpIsBlockedAfterTheThresholdWhateverTheIdentifier(string $firewall, string $path): void
    {
        $this->firewall($firewall);
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            self::assertSame(401, $this->attempt($path, 'user'.$attempt.'@example.com', 'wrong', '10.0.0.1')->getStatusCode());
        }

        self::assertSame([LoginThrottlingLimit::Ip], $this->limitsReached());
        self::assertSame(401, $this->attempt($path, 'admin@example.com', self::PASSWORD, '10.0.0.1')->getStatusCode());
    }

    #[DataProvider('firewalls')]
    public function testAnIdentifierIsBlockedAfterTheThresholdWhateverTheIp(string $firewall, string $path): void
    {
        $this->firewall($firewall);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertSame(401, $this->attempt($path, 'admin@example.com', 'wrong', '10.0.0.'.$attempt)->getStatusCode());
        }

        self::assertSame([LoginThrottlingLimit::Identifier], $this->limitsReached());
        self::assertSame(401, $this->attempt($path, 'admin@example.com', self::PASSWORD, '10.0.0.9')->getStatusCode());
    }

    public function testTheLimitsOfTheFirewallsAreSeparate(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->attempt('/admin/login-check', 'admin@example.com', 'wrong', '10.0.0.1');
        }

        self::assertSame(401, $this->attempt('/admin/login-check', 'admin@example.com', self::PASSWORD, '10.0.0.1')->getStatusCode());
        self::assertSame(200, $this->attempt('/login-check', 'admin@example.com', self::PASSWORD, '10.0.0.1')->getStatusCode());
    }

    /**
     * Maps the data provider names to the Symfony firewall name.
     */
    private function firewall(string $name): string
    {
        return 'admin' === $name ? 'admin' : 'shop';
    }

    private function attempt(string $path, string $identifier, string $password, string $ip): Response
    {
        return $this->send('POST', $path, json: ['_username' => $identifier, '_password' => $password], server: ['REMOTE_ADDR' => $ip]);
    }

    /**
     * @return list<LoginThrottlingLimit>
     */
    private function limitsReached(): array
    {
        return array_map(static fn (LoginThrottledEvent $event): LoginThrottlingLimit => $event->limit, $this->events);
    }
}
