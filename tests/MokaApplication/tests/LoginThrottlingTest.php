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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The thresholds of the application are 3 attempts for an identifier and an IP, 5 for an IP and 4 for an identifier.
 *
 * The authenticator of Moka reads its own "email" field: the limiter is given the identifier of the passport.
 */
final class LoginThrottlingTest extends MokaKernelTestCase
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
    }

    public function testAnIdentifierIsBlockedFromAnIpAfterTheThreshold(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->assertRedirectedTo('/admin/login', $this->login(password: 'wrong', ip: '10.0.0.1'));
        }

        self::assertSame([LoginThrottlingLimit::IdentifierAndIp], $this->limitsReached());
        self::assertSame('moka', $this->events[0]->firewall);
        // The attempt is rejected before the right credentials are checked.
        $this->assertRedirectedTo('/admin/login', $this->login(ip: '10.0.0.1'));
        self::assertStringContainsString('Too many failed login attempts', (string) $this->send('GET', '/admin/login')->getContent());
    }

    public function testAnIpIsBlockedAfterTheThresholdWhateverTheIdentifier(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('user'.$attempt.'@example.com', 'wrong', '10.0.0.1');
        }

        $this->assertRedirectedTo('/admin/login', $this->login(ip: '10.0.0.1'));
        self::assertSame([LoginThrottlingLimit::Ip], $this->limitsReached());
    }

    public function testAnIdentifierIsBlockedAfterTheThresholdWhateverTheIp(): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->login(password: 'wrong', ip: '10.0.0.'.$attempt);
        }

        $this->assertRedirectedTo('/admin/login', $this->login(ip: '10.0.0.9'));
        self::assertSame([LoginThrottlingLimit::Identifier], $this->limitsReached());
    }

    public function testASuccessfulLoginClearsTheFailedAttempts(): void
    {
        $this->login(password: 'wrong', ip: '10.0.0.1');
        $this->login(password: 'wrong', ip: '10.0.0.1');
        $this->assertRedirectedTo('/admin/', $this->login(ip: '10.0.0.1'));
        $this->cookies = [];

        $this->login(password: 'wrong', ip: '10.0.0.1');
        $this->login(password: 'wrong', ip: '10.0.0.1');

        $this->assertRedirectedTo('/admin/', $this->login(ip: '10.0.0.1'));
        self::assertSame([], $this->events);
    }

    /**
     * @return list<LoginThrottlingLimit>
     */
    private function limitsReached(): array
    {
        return array_values(array_unique(array_map(static fn (LoginThrottledEvent $event): LoginThrottlingLimit => $event->limit, $this->events), \SORT_REGULAR));
    }
}
