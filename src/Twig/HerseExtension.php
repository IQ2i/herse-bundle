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

namespace IQ2i\HerseBundle\Twig;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use IQ2i\HerseBundle\Security\FirewallRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class HerseExtension extends AbstractExtension
{
    public function __construct(
        private readonly ?PasswordPolicyDescriber $passwordPolicy,
        private readonly ?FirewallRegistry $firewalls,
        private readonly string $layout,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('herse_password_policy', $this->passwordPolicy(...)),
            new TwigFunction('herse_layout', $this->layout(...)),
        ];
    }

    /**
     * @return list<string> the rules of the password policy, translated
     */
    public function passwordPolicy(): array
    {
        return $this->passwordPolicy?->rules() ?? throw new \LogicException('herse_password_policy() requires iq2i_herse.password_policy to be enabled.');
    }

    /**
     * @return string the template the screens of the bundle extend: the one of the current firewall, or iq2i_herse.ui.layout
     */
    public function layout(): string
    {
        return $this->firewalls?->layout() ?? $this->layout;
    }
}
