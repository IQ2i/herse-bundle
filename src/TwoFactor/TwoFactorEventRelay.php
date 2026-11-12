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

namespace IQ2i\HerseBundle\TwoFactor;

use IQ2i\HerseBundle\Event\TwoFactorFailedEvent;
use IQ2i\HerseBundle\Event\TwoFactorSucceededEvent;
use IQ2i\HerseBundle\User\UserResolver;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Relays the success and the failure of a second factor as events of the bundle, so that a project or vigie-bundle
 * listens to Herse without depending on scheb.
 *
 * @internal
 */
final class TwoFactorEventRelay
{
    public function __construct(
        private readonly UserResolver $users,
        private readonly Security $security,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $this->relay($event, TwoFactorSucceededEvent::class);
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $this->relay($event, TwoFactorFailedEvent::class);
    }

    /**
     * @param class-string<TwoFactorSucceededEvent|TwoFactorFailedEvent> $class
     */
    private function relay(TwoFactorAuthenticationEvent $event, string $class): void
    {
        $user = $event->getToken()->getUser();
        if (null === $user) {
            return;
        }

        $request = $event->getRequest();
        $this->dispatcher->dispatch(new $class($this->users->reference($user), $user, $this->security->getFirewallConfig($request)?->getName() ?? '', $request->getClientIp()));
    }
}
