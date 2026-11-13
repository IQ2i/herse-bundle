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
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The link from a list of users to the screen resetting the second factor of one of them.
 */
final class TwoFactorResetLink
{
    public function __construct(
        private readonly FirewallRegistry $firewalls,
        private readonly UserResolver $users,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
        private readonly string $role,
    ) {
    }

    /**
     * @param string|null $back the path the screen returns to, the current page by default
     *
     * @return string|null the path of the reset screen for the user, null when there is nothing to reset or when the
     *                     current user may not do it
     */
    public function path(mixed $user, ?string $back = null): ?string
    {
        $firewall = $this->firewalls->current();
        $reference = $user instanceof TotpTwoFactorInterface && $user->isTotpAuthenticationEnabled() ? $this->users->reference($user) : null;
        if (null === $firewall || null === $reference || !$this->security->isGranted($this->role)) {
            return null;
        }

        return $this->urls->generate(HerseRouteLoader::name($firewall, HerseRouteLoader::TWO_FACTOR_RESET), [
            'type' => $reference->type,
            'id' => $reference->identifier,
            'back' => $back ?? $this->requests->getMainRequest()?->getRequestUri(),
        ]);
    }
}
