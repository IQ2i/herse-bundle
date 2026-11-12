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

namespace IQ2i\HerseBundle\Tests\RateLimiter;

use IQ2i\HerseBundle\Event\LoginThrottledEvent;
use IQ2i\HerseBundle\RateLimiter\LoginRateLimiter;
use IQ2i\HerseBundle\RateLimiter\LoginThrottlingLimit;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginRateLimiterTest extends TestCase
{
    /** @var list<LoginThrottledEvent> */
    private array $events = [];

    private RecordingStorage $storage;

    private ?string $firewall = 'shop';

    protected function setUp(): void
    {
        $this->events = [];
        $this->firewall = 'shop';
    }

    public function testAttemptsAreCountedOnceTheIdentifierIsNormalized(): void
    {
        $limiter = $this->limiter();

        $limiter->consume($this->request(' Alice '));
        $limiter->consume($this->request('ALICE'));

        self::assertSame(1, $limiter->peek($this->request('alice'))->getRemainingTokens());
    }

    public function testTheLimitsAreScopedToTheFirewall(): void
    {
        $limiter = $this->limiter();
        $limiter->consume($this->request('alice'));
        $limiter->consume($this->request('alice'));
        $limiter->consume($this->request('alice'));

        $this->firewall = 'admin';

        self::assertTrue($limiter->peek($this->request('alice'))->isAccepted());
        self::assertSame(3, $limiter->peek($this->request('alice'))->getRemainingTokens());
    }

    public function testPeekingConsumesNothing(): void
    {
        $limiter = $this->limiter();

        $limiter->peek($this->request('alice'));
        $limiter->peek($this->request('alice'));

        self::assertSame(3, $limiter->peek($this->request('alice'))->getRemainingTokens());
    }

    public function testTheMostRestrictiveLimitWins(): void
    {
        $limiter = $this->limiter();

        // The IP limit (6) is the loosest of the three: the combined one (3) is exhausted first.
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $rateLimit = $limiter->consume($this->request('alice'));
        }

        self::assertTrue($rateLimit->isAccepted());
        self::assertSame(0, $rateLimit->getRemainingTokens());
        self::assertFalse($limiter->consume($this->request('alice'))->isAccepted());
    }

    public function testEachLimitIsReportedOnceWhenItIsReached(): void
    {
        $limiter = $this->limiter();

        $this->failedAttempt($limiter, $this->request('alice'));
        $this->failedAttempt($limiter, $this->request('alice'));
        self::assertCount(0, $this->events);

        $this->failedAttempt($limiter, $this->request('alice'));

        self::assertCount(1, $this->events);
        self::assertSame(LoginThrottlingLimit::IdentifierAndIp, $this->events[0]->limit);
        self::assertSame('alice', $this->events[0]->identifier);
        self::assertSame('203.0.113.7', $this->events[0]->ip);
        self::assertSame('shop', $this->events[0]->firewall);
    }

    public function testEveryLimitIsReportedWhenItIsReached(): void
    {
        $limiter = $this->limiter(identifierLimit: 2, ipLimit: 2, identifierAndIpLimit: 2);

        $this->failedAttempt($limiter, $this->request('alice'));
        $this->failedAttempt($limiter, $this->request('alice'));

        self::assertEqualsCanonicalizing(
            [LoginThrottlingLimit::IdentifierAndIp, LoginThrottlingLimit::Ip, LoginThrottlingLimit::Identifier],
            array_map(static fn (LoginThrottledEvent $event): LoginThrottlingLimit => $event->limit, $this->events),
        );
    }

    public function testALimitIsReportedWhenTheNativeListenerConsumesBeforeTheLoginFails(): void
    {
        $limiter = $this->limiter(identifierAndIpLimit: 1);
        $request = $this->request('alice');

        $limiter->consume($request);
        self::assertCount(0, $this->events, 'The outcome of the login is not known yet.');

        $limiter->onLoginFailure($this->failure($request));

        self::assertCount(1, $this->events);
    }

    public function testASuccessfulLoginTakingTheLastTokenReportsNothing(): void
    {
        $limiter = $this->limiter(identifierAndIpLimit: 1);
        $request = $this->request('alice');

        $limiter->consume($request);
        $limiter->reset($request);

        self::assertCount(0, $this->events);
    }

    public function testALimitIsReportedOnlyOnceForAFailedLogin(): void
    {
        $limiter = $this->limiter(identifierAndIpLimit: 1);
        $request = $this->request('alice');

        $limiter->consume($request);
        $limiter->onLoginFailure($this->failure($request));
        $limiter->onLoginFailure($this->failure($request));

        self::assertCount(1, $this->events);
    }

    public function testTheIdentifierLimitIsOptional(): void
    {
        $limiter = $this->limiter(withIdentifierLimit: false);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->failedAttempt($limiter, $this->request('alice', '203.0.113.'.$attempt));
        }

        self::assertCount(0, $this->events);
        self::assertTrue($limiter->peek($this->request('alice', '203.0.113.99'))->isAccepted());
    }

    public function testAnAttemptWithoutIdentifierOnlyCountsForTheIp(): void
    {
        $limiter = $this->limiter(identifierAndIpLimit: 2, identifierLimit: 2);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->failedAttempt($limiter, $this->request('  '));
        }

        self::assertCount(0, $this->events);
        self::assertSame(2, $limiter->peek($this->request('  '))->getRemainingTokens(), 'Only the limit of the IP, of 6, was consumed.');
        $limiter->reset($this->request(''));
        self::assertSame(2, $limiter->peek($this->request(''))->getRemainingTokens());
    }

    public function testResetClearsTheLimitsOfTheIdentifier(): void
    {
        $limiter = $this->limiter();
        $limiter->consume($this->request('alice'));
        $limiter->consume($this->request('alice'));

        $limiter->reset($this->request('alice'));

        self::assertSame(3, $limiter->peek($this->request('alice'))->getRemainingTokens());
    }

    public function testTheIpLimitSurvivesAReset(): void
    {
        $limiter = $this->limiter(ipLimit: 3, identifierAndIpLimit: 10, identifierLimit: 10);
        $limiter->consume($this->request('alice'));
        $limiter->consume($this->request('bob'));

        $limiter->reset($this->request('alice'));

        self::assertSame(1, $limiter->peek($this->request('carol'))->getRemainingTokens());
    }

    public function testNoIdentifierNorIpIsReadableInTheStorageKeys(): void
    {
        $limiter = $this->limiter();

        $limiter->consume($this->request('alice@example.com'));

        self::assertNotSame([], $this->storage->ids);
        foreach ($this->storage->ids as $id) {
            self::assertStringNotContainsString('alice', $id);
            self::assertStringNotContainsString('203.0.113.7', $id);
        }
    }

    public function testAnInvalidEncodingOrAMissingIdentifierIsSupported(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->consume($this->request("ali\xB1ce"))->isAccepted());
        self::assertTrue($limiter->consume(new Request())->isAccepted());
    }

    public function testTheLimitsApplyWithoutAnyFirewall(): void
    {
        $this->firewall = null;
        $limiter = $this->limiter();

        $limiter->consume($this->request('alice'));

        self::assertSame(2, $limiter->peek($this->request('alice'))->getRemainingTokens());
    }

    private function limiter(int $identifierAndIpLimit = 3, int $ipLimit = 6, int $identifierLimit = 4, bool $withIdentifierLimit = true): LoginRateLimiter
    {
        $this->storage = new RecordingStorage();

        $security = $this->createStub(Security::class);
        $security->method('getFirewallConfig')->willReturnCallback(
            fn (): ?FirewallConfig => null === $this->firewall ? null : new FirewallConfig($this->firewall, 'security.user_checker'),
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LoginThrottledEvent::class, function (LoginThrottledEvent $event): void {
            $this->events[] = $event;
        });

        return new LoginRateLimiter(
            $this->factory('identifier_and_ip', $identifierAndIpLimit),
            $this->factory('ip', $ipLimit),
            $withIdentifierLimit ? $this->factory('identifier', $identifierLimit) : null,
            $security,
            $dispatcher,
            'secret',
        );
    }

    /**
     * A failed attempt, as the native listener reports it once the login has failed.
     */
    private function failedAttempt(LoginRateLimiter $limiter, Request $request): void
    {
        $limiter->onLoginFailure($this->failure($request));
        $limiter->consume($request);
    }

    private function failure(Request $request): LoginFailureEvent
    {
        return new LoginFailureEvent(new BadCredentialsException(), $this->createStub(AuthenticatorInterface::class), $request, null, 'shop');
    }

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], $this->storage);
    }

    private function request(string $identifier, string $ip = '203.0.113.7'): Request
    {
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $identifier);

        return $request;
    }
}

final class RecordingStorage implements StorageInterface
{
    /** @var list<string> */
    public array $ids = [];

    private readonly InMemoryStorage $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStorage();
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->ids[] = $limiterState->getId();
        $this->inner->save($limiterState);
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        return $this->inner->fetch($limiterStateId);
    }

    public function delete(string $limiterStateId): void
    {
        $this->inner->delete($limiterStateId);
    }
}
