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

namespace IQ2i\HerseBundle\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The firewalls declared under iq2i_herse.firewalls, and the one of the current request.
 *
 * @internal
 */
final class FirewallRegistry
{
    /**
     * @param array<string, array{path_prefix: string, home: string|null, layout: string|null, two_factor: bool}> $firewalls
     * @param bool                                                                                                $secondFactorRequired whether the users of the firewalls asking a second factor must have one
     */
    public function __construct(
        private readonly array $firewalls,
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly bool $secondFactorRequired = false,
    ) {
    }

    /**
     * @return string|null the name of the declared firewall the current request goes through
     */
    public function current(): ?string
    {
        $request = $this->requests->getMainRequest();
        $name = null === $request ? null : $this->security->getFirewallConfig($request)?->getName();

        return null !== $name && isset($this->firewalls[$name]) ? $name : null;
    }

    /**
     * Whether a user of the firewall cannot go on without a second factor.
     */
    public function asksSecondFactor(string $firewall): bool
    {
        return $this->secondFactorRequired && ($this->firewalls[$firewall]['two_factor'] ?? false);
    }

    /**
     * @return string the URL a screen of the bundle leaves to: the "home" of the firewall, a path or a route name
     */
    public function home(string $firewall): string
    {
        $home = $this->firewalls[$firewall]['home'] ?? null;
        if (null === $home) {
            return '' === $this->firewalls[$firewall]['path_prefix'] ? '/' : $this->firewalls[$firewall]['path_prefix'];
        }

        return str_starts_with($home, '/') || null === $this->urls ? $home : $this->urls->generate($home);
    }

    /**
     * @return string|null the layout of the screens of the bundle on the current firewall, when it has its own
     */
    public function layout(): ?string
    {
        $name = $this->current();

        return null === $name ? null : $this->firewalls[$name]['layout'];
    }
}
