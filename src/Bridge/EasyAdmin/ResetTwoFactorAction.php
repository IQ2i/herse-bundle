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

namespace IQ2i\HerseBundle\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use IQ2i\HerseBundle\Security\FirewallRegistry;
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "reset two-factor" action of a user CRUD of EasyAdmin: a link to the confirmation screen of the bundle, shown on
 * the users having an authenticator app, which comes back to the page it was clicked on.
 *
 * In a CRUD controller: $actions->add(Crud::PAGE_INDEX, $this->resetTwoFactorAction->create()).
 */
final class ResetTwoFactorAction
{
    public const NAME = 'herseResetTwoFactor';

    public function __construct(
        private readonly FirewallRegistry $firewalls,
        private readonly UserResolver $users,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function create(): Action
    {
        return Action::new(self::NAME, $this->translator?->trans('Reset two-factor', [], 'iq2i_herse') ?? 'Reset two-factor')
            ->linkToUrl($this->url(...))
            ->displayIf($this->isDisplayedFor(...))
        ;
    }

    /**
     * Whether there is something to reset: the user has an authenticator app, and its class is declared in iq2i_herse.users.
     */
    public function isDisplayedFor(mixed $user): bool
    {
        return $user instanceof TotpTwoFactorInterface && $user->isTotpAuthenticationEnabled() && null !== $this->users->reference($user);
    }

    /**
     * @return string the URL of the confirmation screen for the user, on the firewall of the current request
     */
    public function url(object $user): string
    {
        $firewall = $this->firewalls->current() ?? throw new \LogicException('The "reset two-factor" action is used on a firewall that is not declared in iq2i_herse.firewalls.');
        $reference = $this->users->reference($user) ?? throw new \LogicException(\sprintf('The class "%s" is not declared in iq2i_herse.users.', $user::class));

        return $this->urls->generate(HerseRouteLoader::name($firewall, HerseRouteLoader::TWO_FACTOR_RESET), [
            'type' => $reference->type,
            'id' => $reference->identifier,
            'back' => $this->requests->getMainRequest()?->getRequestUri(),
        ]);
    }
}
