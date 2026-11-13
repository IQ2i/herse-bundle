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
        $this->assertAllowed($this->firewall($event), $event->getToken()->getUserIdentifier());
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        $this->recordFailure($this->firewall($event), $event->getToken()->getUserIdentifier(), $user, $event->getRequest()->getClientIp());
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $this->reset($this->firewall($event), $event->getToken()->getUserIdentifier());
    }

    /**
     * The codes checked outside of the login, as on the enrollment screen, share the attempts of the login.
     *
     * @throws TooManyLoginAttemptsAuthenticationException when the user has no attempt left
     */
    public function assertAllowed(string $firewall, string $userIdentifier): void
    {
        $limit = $this->limiter($firewall, $userIdentifier)->consume(0);
        // Peeking is accepted even when no token is left, depending on the version of the Rate Limiter.
        if (!$limit->isAccepted() || 0 === $limit->getRemainingTokens()) {
            // The retry time is rounded up to the second and time() down: one second of the difference is only rounding.
            throw new TooManyLoginAttemptsAuthenticationException(max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time() - 1) / 60)));
        }
    }

    public function recordFailure(string $firewall, string $userIdentifier, ?object $user, ?string $ip): void
    {
        $limit = $this->limiter($firewall, $userIdentifier)->consume(1);
        if (!$limit->isAccepted() || 0 !== $limit->getRemainingTokens() || null === $user) {
            return;
        }

        $this->dispatcher->dispatch(new TwoFactorThrottledEvent($this->users->reference($user), $user, $firewall, $ip));
    }

    public function reset(string $firewall, string $userIdentifier): void
    {
        $this->limiter($firewall, $userIdentifier)->reset();
    }

    private function limiter(string $firewall, string $userIdentifier): LimiterInterface
    {
        return $this->factory->create(LimiterKey::hash($this->secret, $firewall, $userIdentifier));
    }

    private function firewall(TwoFactorAuthenticationEvent $event): string
    {
        return $this->security->getFirewallConfig($event->getRequest())?->getName() ?? '';
    }
}
