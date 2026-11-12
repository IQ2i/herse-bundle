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

namespace IQ2i\HerseBundle\RateLimiter;

use IQ2i\HerseBundle\Event\LoginThrottledEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Login limiter combining up to three limits, each scoped to the firewall:
 * identifier + IP, IP alone and (optionally) identifier alone.
 *
 * To be plugged in with the native "login_throttling.limiter" firewall option.
 */
final class LoginRateLimiter implements PeekableRequestRateLimiterInterface
{
    private const EXHAUSTED_ATTRIBUTE = '_iq2i_herse_exhausted_limits';
    private const FAILED_ATTRIBUTE = '_iq2i_herse_login_failed';

    /**
     * @param non-empty-string $secret
     */
    public function __construct(
        private readonly RateLimiterFactory $identifierAndIpFactory,
        private readonly RateLimiterFactory $ipFactory,
        private readonly ?RateLimiterFactory $identifierFactory,
        private readonly Security $security,
        private readonly EventDispatcherInterface $eventDispatcher,
        #[\SensitiveParameter] private readonly string $secret,
    ) {
    }

    public function consume(Request $request): RateLimit
    {
        $context = $this->context($request);
        $rateLimits = [];
        $exhausted = $this->exhausted($request);

        foreach ($this->limiters($context) as $name => $limiter) {
            $rateLimit = $limiter->consume(1);
            $rateLimits[] = $rateLimit;

            // The attempt taking the last token is the one exhausting the limit: the next ones are refused before consuming.
            if ($rateLimit->isAccepted() && 0 === $rateLimit->getRemainingTokens()) {
                $exhausted[] = new LoginThrottledEvent(LoginThrottlingLimit::from($name), $context['identifier'], $context['ip'], $context['firewall']);
            }
        }

        $request->attributes->set(self::EXHAUSTED_ATTRIBUTE, $exhausted);
        $this->dispatchExhausted($request);

        /* @var non-empty-list<RateLimit> $rateLimits */
        return self::mostRestrictive($rateLimits);
    }

    /**
     * @internal
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $event->getRequest()->attributes->set(self::FAILED_ATTRIBUTE, true);
        $this->dispatchExhausted($event->getRequest());
    }

    public function peek(Request $request): RateLimit
    {
        return self::mostRestrictive(array_map(
            static fn (LimiterInterface $limiter): RateLimit => $limiter->consume(0),
            array_values($this->limiters($this->context($request))),
        ));
    }

    /**
     * Only the limits tied to the identifier are cleared: the IP limit bounds how many identifiers
     * a single client may try, so it must survive the successful login of any one of them.
     */
    public function reset(Request $request): void
    {
        $limiters = $this->limiters($this->context($request));
        unset($limiters[LoginThrottlingLimit::Ip->value]);

        foreach ($limiters as $limiter) {
            $limiter->reset();
        }
    }

    /**
     * Depending on its version, the native listener consumes before the credentials are checked or once the login
     * has failed: a limit is only reported when both happened, so that a successful login never reports one.
     */
    private function dispatchExhausted(Request $request): void
    {
        if (true !== $request->attributes->get(self::FAILED_ATTRIBUTE)) {
            return;
        }

        $exhausted = $this->exhausted($request);
        $request->attributes->remove(self::EXHAUSTED_ATTRIBUTE);

        foreach ($exhausted as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * @return list<LoginThrottledEvent>
     */
    private function exhausted(Request $request): array
    {
        $exhausted = $request->attributes->get(self::EXHAUSTED_ATTRIBUTE, []);

        return \is_array($exhausted) ? array_values(array_filter($exhausted, static fn (mixed $event): bool => $event instanceof LoginThrottledEvent)) : [];
    }

    /**
     * @return array{firewall: string|null, identifier: string, ip: string|null}
     */
    private function context(Request $request): array
    {
        $identifier = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        $identifier = \is_string($identifier) ? trim($identifier) : '';

        return [
            'firewall' => $this->security->getFirewallConfig($request)?->getName(),
            'identifier' => preg_match('//u', $identifier) ? mb_strtolower($identifier, 'UTF-8') : strtolower($identifier),
            'ip' => $request->getClientIp(),
        ];
    }

    /**
     * @param array{firewall: string|null, identifier: string, ip: string|null} $context
     *
     * @return non-empty-array<string, LimiterInterface> keyed by LoginThrottlingLimit value
     */
    private function limiters(array $context): array
    {
        $firewall = $context['firewall'] ?? '';
        $ip = $context['ip'] ?? '';

        $limiters = [LoginThrottlingLimit::Ip->value => $this->ipFactory->create($this->key($firewall, $ip))];

        // No identifier (the login failed before any user was named): its limits would be one bucket shared by everybody.
        if ('' === $context['identifier']) {
            return $limiters;
        }

        $limiters[LoginThrottlingLimit::IdentifierAndIp->value] = $this->identifierAndIpFactory->create($this->key($firewall, $context['identifier'], $ip));
        if (null !== $this->identifierFactory) {
            $limiters[LoginThrottlingLimit::Identifier->value] = $this->identifierFactory->create($this->key($firewall, $context['identifier']));
        }

        return $limiters;
    }

    private function key(string ...$parts): string
    {
        return LimiterKey::hash($this->secret, ...$parts);
    }

    /**
     * @param non-empty-list<RateLimit> $rateLimits
     */
    private static function mostRestrictive(array $rateLimits): RateLimit
    {
        $result = array_shift($rateLimits);

        foreach ($rateLimits as $rateLimit) {
            if ($result->isAccepted() !== $rateLimit->isAccepted()) {
                $result = $result->isAccepted() ? $rateLimit : $result;
            } elseif ($result->getRemainingTokens() > $rateLimit->getRemainingTokens()) {
                $result = $rateLimit;
            } elseif ($result->getRemainingTokens() === $rateLimit->getRemainingTokens() && $result->getRetryAfter() < $rateLimit->getRetryAfter()) {
                $result = $rateLimit;
            }
        }

        return $result;
    }
}
