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

/**
 * The firewalls declared under iq2i_herse.firewalls, and the one of the current request.
 *
 * @internal
 */
final class FirewallRegistry
{
    /**
     * @param array<string, array{path_prefix: string, layout: string|null}> $firewalls
     */
    public function __construct(
        private readonly array $firewalls,
        private readonly Security $security,
        private readonly RequestStack $requests,
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
     * @return string|null the layout of the screens of the bundle on the current firewall, when it has its own
     */
    public function layout(): ?string
    {
        $name = $this->current();

        return null === $name ? null : $this->firewalls[$name]['layout'];
    }
}
