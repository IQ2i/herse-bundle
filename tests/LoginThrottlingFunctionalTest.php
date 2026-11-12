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

use IQ2i\HerseBundle\Event\LoginThrottledEvent;
use IQ2i\HerseBundle\RateLimiter\LoginThrottlingLimit;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;

final class LoginThrottlingFunctionalTest extends HerseKernelTestCase
{
    /** @var list<LoginThrottledEvent> */
    private array $events = [];

    protected static function environment(): string
    {
        return 'login_throttling';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        /** @var CacheItemPoolInterface $cache */
        $cache = $this->kernel->getContainer()->get('test.login_throttling.cache');
        $cache->clear();
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $this->kernel->getContainer()->get('test.event_dispatcher');
        $dispatcher->addListener(LoginThrottledEvent::class, function (LoginThrottledEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheLoginSucceedsWithValidCredentials(): void
    {
        self::assertSame(200, $this->login('alice', 'secret', '10.0.0.1'));
    }

    public function testTheSameIdentifierFromTheSameIpIsBlockedAfterTheThreshold(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame(401, $this->login('alice', 'wrong', '10.0.0.1'));
        }

        self::assertSame([LoginThrottlingLimit::IdentifierAndIp], $this->limitsReached());
        self::assertSame('alice', $this->events[0]->identifier);
        self::assertSame('10.0.0.1', $this->events[0]->ip);
        self::assertSame('main', $this->events[0]->firewall);

        // Correct credentials are refused too: the attempt is rejected before they are checked.
        self::assertSame(401, $this->login('alice', 'secret', '10.0.0.1'));
    }

    public function testTheSameIdentifierFromAnotherIpIsNotBlockedByTheCombinedLimit(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->login('alice', 'wrong', '10.0.0.1');
        }

        self::assertSame(200, $this->login('alice', 'secret', '10.0.0.2'));
    }

    public function testTheIdentifierIsNormalizedBeforeCountingTheAttempts(): void
    {
        $this->login('alice', 'wrong', '10.0.0.1');
        $this->login('ALICE', 'wrong', '10.0.0.1');
        $this->login(' Alice ', 'wrong', '10.0.0.1');

        self::assertSame(401, $this->login('alice', 'secret', '10.0.0.1'));
    }

    public function testAnIpIsBlockedAfterTheThresholdWhateverTheIdentifier(): void
    {
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            self::assertSame(401, $this->login('user'.$attempt, 'wrong', '10.0.0.1'));
        }

        self::assertSame([LoginThrottlingLimit::Ip], $this->limitsReached());
        self::assertSame(401, $this->login('alice', 'secret', '10.0.0.1'));
    }

    public function testAnIdentifierIsBlockedAfterTheThresholdWhateverTheIp(): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame(401, $this->login('alice', 'wrong', '10.0.0.'.$attempt));
        }

        self::assertSame([LoginThrottlingLimit::Identifier], $this->limitsReached());
        self::assertSame(401, $this->login('alice', 'secret', '10.0.0.5'));
    }

    public function testASuccessfulLoginClearsTheLimitsOfTheIdentifier(): void
    {
        // Older versions of the native listener never reset a peekable limiter: the call is theirs to make.
        if (!(new \ReflectionClass(LoginThrottlingListener::class))->hasConstant('UNTOUCHED_LIMITER_ATTRIBUTE')) {
            self::markTestSkipped('The installed security-http does not reset the login limiter after a successful login.');
        }

        $this->login('alice', 'wrong', '10.0.0.1');
        $this->login('alice', 'wrong', '10.0.0.1');
        self::assertSame(200, $this->login('alice', 'secret', '10.0.0.1'));

        // Five failures overall would exceed the threshold of 3 if the successful login had not reset it.
        $this->login('alice', 'wrong', '10.0.0.1');
        $this->login('alice', 'wrong', '10.0.0.1');

        self::assertSame(200, $this->login('alice', 'secret', '10.0.0.1'));
        self::assertCount(0, $this->events);
    }

    /**
     * @return list<LoginThrottlingLimit>
     */
    private function limitsReached(): array
    {
        return array_map(static fn (LoginThrottledEvent $event): LoginThrottlingLimit => $event->limit, $this->events);
    }

    /**
     * @phpstan-impure
     */
    private function login(string $username, string $password, string $ip): int
    {
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $username, 'password' => $password], \JSON_THROW_ON_ERROR));

        return $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false)->getStatusCode();
    }
}
