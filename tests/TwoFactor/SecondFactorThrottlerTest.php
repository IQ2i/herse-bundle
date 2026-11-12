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

namespace IQ2i\HerseBundle\Tests\TwoFactor;

use IQ2i\HerseBundle\Event\TwoFactorThrottledEvent;
use IQ2i\HerseBundle\TwoFactor\SecondFactorThrottler;
use IQ2i\HerseBundle\User\UserResolver;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecondFactorThrottlerTest extends TestCase
{
    private string $firewall = 'shop';

    /** @var list<TwoFactorThrottledEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        if (!class_exists(TwoFactorAuthenticationEvent::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }

        $this->firewall = 'shop';
        $this->events = [];
    }

    public function testTheAttemptsAreRefusedOnceTheLimitIsReached(): void
    {
        $throttler = $this->throttler();
        $throttler->onAttempt($this->event('alice'));
        $throttler->onFailure($this->event('alice'));
        $throttler->onAttempt($this->event('alice'));
        $throttler->onFailure($this->event('alice'));

        try {
            $throttler->onAttempt($this->event('alice'));
            self::fail('The attempt was expected to be refused.');
        } catch (TooManyLoginAttemptsAuthenticationException $exception) {
            self::assertSame(['%minutes%' => 15, '%count%' => 15], $exception->getMessageData());
        }
    }

    public function testTheLimitIsScopedToTheUserAndTheFirewall(): void
    {
        $throttler = $this->throttler();
        $throttler->onFailure($this->event('alice'));
        $throttler->onFailure($this->event('alice'));

        $throttler->onAttempt($this->event('bob'));
        $this->firewall = 'admin';
        $throttler->onAttempt($this->event('alice'));

        $this->expectException(TooManyLoginAttemptsAuthenticationException::class);
        $this->firewall = 'shop';
        $throttler->onAttempt($this->event('alice'));
    }

    public function testASuccessClearsTheLimit(): void
    {
        $throttler = $this->throttler();
        $throttler->onFailure($this->event('alice'));
        $throttler->onFailure($this->event('alice'));

        $throttler->onSuccess($this->event('alice'));
        $throttler->onAttempt($this->event('alice'));

        self::assertCount(1, $this->events);
    }

    public function testTheLimitIsReportedOnceWithTheUser(): void
    {
        $throttler = $this->throttler();
        $throttler->onFailure($this->event('alice'));
        self::assertCount(0, $this->events);

        $throttler->onFailure($this->event('alice'));
        $throttler->onFailure($this->event('alice'));

        self::assertCount(1, $this->events);
        self::assertNull($this->events[0]->reference, 'The user class is not declared.');
        self::assertSame('alice', $this->events[0]->user instanceof InMemoryUser ? $this->events[0]->user->getUserIdentifier() : null);
        self::assertSame('shop', $this->events[0]->firewall);
        self::assertSame('203.0.113.9', $this->events[0]->ip);
    }

    public function testATokenWithoutUserReportsNothing(): void
    {
        $throttler = $this->throttler();
        $event = new TwoFactorAuthenticationEvent(Request::create('/'), new NullToken());

        $throttler->onFailure($event);
        $throttler->onFailure($event);

        self::assertSame([], $this->events);
    }

    private function throttler(): SecondFactorThrottler
    {
        $security = $this->createStub(Security::class);
        $security->method('getFirewallConfig')->willReturnCallback(fn (): FirewallConfig => new FirewallConfig($this->firewall, 'security.user_checker'));

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TwoFactorThrottledEvent::class, function (TwoFactorThrottledEvent $event): void {
            $this->events[] = $event;
        });

        return new SecondFactorThrottler(
            new RateLimiterFactory(['id' => 'two_factor', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '15 minutes'], new InMemoryStorage()),
            new UserResolver([], PropertyAccess::createPropertyAccessor()),
            $security,
            $dispatcher,
            'secret',
        );
    }

    private function event(string $identifier): TwoFactorAuthenticationEvent
    {
        return new TwoFactorAuthenticationEvent(Request::create('/2fa_check', server: ['REMOTE_ADDR' => '203.0.113.9']), new UsernamePasswordToken(new InMemoryUser($identifier, null), 'main'));
    }
}
