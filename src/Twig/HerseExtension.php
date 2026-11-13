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
use IQ2i\HerseBundle\TwoFactor\TwoFactorResetLink;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class HerseExtension extends AbstractExtension
{
    public const FRAME = '@IQ2iHerse/frame.html.twig';

    public function __construct(
        private readonly ?PasswordPolicyDescriber $passwordPolicy,
        private readonly ?FirewallRegistry $firewalls,
        private readonly string $layout,
        private readonly ?TwoFactorResetLink $resetLink = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('herse_password_policy', $this->passwordPolicy(...)),
            new TwigFunction('herse_layout', $this->frame(...)),
            new TwigFunction('herse_base_layout', $this->layout(...)),
            new TwigFunction('herse_template', $this->template(...)),
            new TwigFunction('herse_two_factor_reset_path', $this->twoFactorResetPath(...)),
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
     * @param 'security'|'account' $kind a step of the login, or a page of a logged in user
     *
     * @return list<string> the templates a screen of the bundle may extend, the first one that exists being used: the
     *                      frame of the theme of the current firewall, then the one placing the screen in the layout
     */
    public function frame(string $kind = 'account'): array
    {
        $theme = $this->firewalls?->theme();

        return null === $theme ? [self::FRAME] : [$theme.'/'.$kind.'.html.twig', self::FRAME];
    }

    /**
     * @param string $name a template of the bundle, e.g. "two_factor/_form.html.twig"
     *
     * @return list<string> the templates to include, the first one that exists being used: the one of the theme of the
     *                      current firewall, then the one of the bundle
     */
    public function template(string $name): array
    {
        $theme = $this->firewalls?->theme();

        return null === $theme ? ['@IQ2iHerse/'.$name] : [$theme.'/'.$name, '@IQ2iHerse/'.$name];
    }

    /**
     * @return string the layout of the project: the one of the current firewall, or iq2i_herse.ui.layout
     */
    public function layout(): string
    {
        return $this->firewalls?->layout() ?? $this->layout;
    }

    /**
     * @return string|null the path of the screen resetting the second factor of the user, null when the current user
     *                     has nothing to reset or may not do it
     */
    public function twoFactorResetPath(mixed $user, ?string $back = null): ?string
    {
        return $this->resetLink?->path($user, $back);
    }
}
