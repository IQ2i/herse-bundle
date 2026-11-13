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

use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use IQ2i\HerseBundle\Security\FirewallRegistry;
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Makes the second factor mandatory: scheb asks nothing of an account that has no secret yet, so such an account is
 * sent to the enrollment screen until it has one.
 *
 * @internal
 */
final class EnrollmentListener
{
    public function __construct(
        private readonly FirewallRegistry $firewalls,
        private readonly TokenStorageInterface $tokens,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // A response is already set by the firewall for a logout.
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $firewall = $this->firewalls->current();
        if (null === $firewall || !$this->firewalls->asksSecondFactor($firewall)) {
            return;
        }

        $setupRoute = HerseRouteLoader::name($firewall, HerseRouteLoader::TWO_FACTOR_SETUP);
        $route = $event->getRequest()->attributes->get('_route');
        // The routes starting with an underscore are the ones of the development tools.
        if ($setupRoute === $route || (\is_string($route) && str_starts_with($route, '_'))) {
            return;
        }

        $token = $this->tokens->getToken();
        // Somebody impersonating a user must never enroll their own device on the account of that user.
        if (null === $token || $token instanceof TwoFactorTokenInterface || $token instanceof SwitchUserToken) {
            return;
        }

        $user = $token->getUser();
        if ($user instanceof TotpTwoFactorInterface && !$user->isTotpAuthenticationEnabled()) {
            $event->setResponse(new RedirectResponse($this->urls->generate($setupRoute)));
        }
    }
}
