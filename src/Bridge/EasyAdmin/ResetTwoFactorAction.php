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
use IQ2i\HerseBundle\TwoFactor\TwoFactorResetLink;
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
        private readonly TwoFactorResetLink $link,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function create(): Action
    {
        return Action::new(self::NAME, $this->translator?->trans('Reset two-factor', [], 'iq2i_herse') ?? 'Reset two-factor')
            ->linkToUrl(fn (mixed $user): string => $this->link->path($user) ?? '#')
            ->displayIf(fn (mixed $user): bool => null !== $this->link->path($user))
        ;
    }
}
