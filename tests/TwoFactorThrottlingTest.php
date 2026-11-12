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

use IQ2i\HerseBundle\Event\TwoFactorThrottledEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Three wrong second factors are allowed to a user.
 */
final class TwoFactorThrottlingTest extends TwoFactorKernelTestCase
{
    /** @var list<TwoFactorThrottledEvent> */
    private array $throttledEvents = [];

    protected static function environment(): string
    {
        return 'two_factor_throttling';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttledEvents = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(TwoFactorThrottledEvent::class, function (TwoFactorThrottledEvent $event): void {
            $this->throttledEvents[] = $event;
        });
    }

    public function testTheRightCodeIsRefusedOnceTheWrongOnesReachedTheLimit(): void
    {
        $this->twoFactorAccount();
        $this->signIn();

        $this->submitWrongCodes(2);
        self::assertSame([], $this->throttledEvents);
        $this->submitWrongCodes(1);

        self::assertCount(1, $this->throttledEvents);
        self::assertSame('main', $this->throttledEvents[0]->firewall);
        self::assertSame('203.0.113.7', $this->throttledEvents[0]->ip);
        self::assertSame('customer', $this->throttledEvents[0]->reference?->type);

        $this->assertSecondFactorAsked($this->submitCode($this->emailedCode()));
        $this->assertSecondFactorAsked($this->visitPrivatePage());
        self::assertCount(1, $this->throttledEvents, 'The refused attempts report nothing more.');
    }

    public function testAcceptingThePasswordAgainDoesNotClearTheLimit(): void
    {
        $this->twoFactorAccount();
        $this->signIn();
        $this->submitWrongCodes(3);

        $this->closeSession();
        $this->signIn(ip: '198.51.100.20');

        $this->assertSecondFactorAsked($this->submitCode($this->emailedCode()));
        $this->assertSecondFactorAsked($this->visitPrivatePage());
    }

    public function testTheRightCodeClearsTheLimit(): void
    {
        $this->twoFactorAccount();

        foreach ([1, 2] as $_) {
            $this->closeSession();
            $this->signIn();
            $this->submitWrongCodes(2);
            $this->submitCode($this->emailedCode());
            $this->assertLoggedIn();
        }

        self::assertSame([], $this->throttledEvents);
    }

    public function testTheLimitOfAUserDoesNotLockAnotherOne(): void
    {
        $this->twoFactorAccount();
        $this->twoFactorAccount('bob@example.com');
        $this->signIn();
        $this->submitWrongCodes(3);

        $this->closeSession();
        $this->signIn('bob@example.com');
        $this->submitCode($this->emailedCode());

        $this->assertLoggedIn();
    }

    private function submitWrongCodes(int $count): void
    {
        $wrong = str_pad((string) (((int) $this->emailedCode() + 1) % 1000000), 6, '0', \STR_PAD_LEFT);

        for ($attempt = 1; $attempt <= $count; ++$attempt) {
            $this->assertSecondFactorAsked($this->submitCode($wrong));
        }
    }
}
