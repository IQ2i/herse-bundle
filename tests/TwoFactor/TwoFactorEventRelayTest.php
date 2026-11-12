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

use IQ2i\HerseBundle\Event\TwoFactorFailedEvent;
use IQ2i\HerseBundle\Event\TwoFactorSucceededEvent;
use IQ2i\HerseBundle\Tests\TwoFactorKernelTestCase;
use IQ2i\HerseBundle\TwoFactor\TwoFactorEventRelay;
use IQ2i\HerseBundle\User\UserResolver;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TwoFactorEventRelayTest extends TwoFactorKernelTestCase
{
    public function testATokenWithoutUserRelaysNothing(): void
    {
        $this->relay()->onSuccess(new TwoFactorAuthenticationEvent(Request::create('/'), new NullToken()));
        $this->relay()->onFailure(new TwoFactorAuthenticationEvent(Request::create('/'), new NullToken()));

        self::assertSame([], $this->twoFactorEvents);
    }

    public function testAnUndeclaredUserIsRelayedWithoutReference(): void
    {
        $user = new InMemoryUser('alice', null);
        $event = new TwoFactorAuthenticationEvent(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.9']), new UsernamePasswordToken($user, 'main'));

        $this->relay()->onSuccess($event);
        $this->relay()->onFailure($event);

        self::assertCount(2, $this->twoFactorEvents);
        self::assertInstanceOf(TwoFactorSucceededEvent::class, $this->twoFactorEvents[0]);
        self::assertInstanceOf(TwoFactorFailedEvent::class, $this->twoFactorEvents[1]);
        foreach ($this->twoFactorEvents as $relayed) {
            self::assertNull($relayed->reference);
            self::assertSame($user, $relayed->user);
            self::assertSame('main', $relayed->firewall);
            self::assertSame('203.0.113.9', $relayed->ip);
        }
    }

    private function relay(): TwoFactorEventRelay
    {
        return new TwoFactorEventRelay(
            new UserResolver([], PropertyAccess::createPropertyAccessor()),
            $this->service(Security::class, 'security.helper'),
            $this->service(EventDispatcherInterface::class, 'event_dispatcher'),
        );
    }
}
