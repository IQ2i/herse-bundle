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

use IQ2i\HerseBundle\Event\TwoFactorThrottledEvent;
use IQ2i\HerseBundle\RateLimiter\LimiterKey;
use IQ2i\HerseBundle\User\UserResolver;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bounds the wrong second factors of a user on a firewall, whatever the IP.
 *
 * The login throttling cannot do it: its limits tied to the identifier are cleared each time the password is accepted,
 * which is exactly what someone guessing the code already knows.
 *
 * @internal
 */
final class SecondFactorThrottler
{
    /**
     * @param non-empty-string $secret
     */
    public function __construct(
        private readonly RateLimiterFactory $factory,
        private readonly UserResolver $users,
        private readonly Security $security,
        private readonly EventDispatcherInterface $dispatcher,
        #[\SensitiveParameter] private readonly string $secret,
    ) {
    }

    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $limit = $this->limiter($event)->consume(0);
        // Peeking is accepted even when no token is left, depending on the version of the Rate Limiter.
        if (!$limit->isAccepted() || 0 === $limit->getRemainingTokens()) {
            // The retry time is rounded up to the second and time() down: one second of the difference is only rounding.
            throw new TooManyLoginAttemptsAuthenticationException(max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time() - 1) / 60)));
        }
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $limit = $this->limiter($event)->consume(1);
        if (!$limit->isAccepted() || 0 !== $limit->getRemainingTokens()) {
            return;
        }

        $user = $event->getToken()->getUser();
        if (null === $user) {
            return;
        }

        $request = $event->getRequest();
        $this->dispatcher->dispatch(new TwoFactorThrottledEvent($this->users->reference($user), $user, $this->firewall($event), $request->getClientIp()));
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $this->limiter($event)->reset();
    }

    private function limiter(TwoFactorAuthenticationEvent $event): LimiterInterface
    {
        return $this->factory->create(LimiterKey::hash($this->secret, $this->firewall($event), $event->getToken()->getUserIdentifier()));
    }

    private function firewall(TwoFactorAuthenticationEvent $event): string
    {
        return $this->security->getFirewallConfig($event->getRequest())?->getName() ?? '';
    }
}
