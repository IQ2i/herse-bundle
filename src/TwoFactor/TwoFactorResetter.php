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

use IQ2i\HerseBundle\Event\TwoFactorResetEvent;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use IQ2i\HerseBundle\User\UserResolver;
use Scheb\TwoFactorBundle\Model\PersisterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Removes the authenticator app of a user who lost it: the user enrolls a new one at the next login.
 */
final class TwoFactorResetter
{
    public function __construct(
        private readonly PersisterInterface $persister,
        private readonly UserResolver $users,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?TrustedDevices $devices = null,
    ) {
    }

    /**
     * @param object|null $by the user doing it, null from the console
     */
    public function reset(TotpTwoFactorInterface $user, ?object $by = null): void
    {
        $user->setTotpSecret(null);
        $this->persister->persist($user);

        // A device that skipped the second factor must not outlive it.
        if (null !== $this->devices && null !== $this->users->reference($user)) {
            $this->devices->revokeAll($user);
        }

        $this->dispatcher->dispatch(new TwoFactorResetEvent($this->users->reference($user), $user, $by));
    }
}
