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

use IQ2i\HerseBundle\TrustedDevice\DeviceCookies;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceRepository;
use IQ2i\HerseBundle\User\UserResolver;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Replaces the trusted device cookie of scheb by the device registry: a device that passed the second factor
 * and was trusted by the user is "verified", and a verified device is not asked for the second factor again
 * for a fixed time.
 * Revoking the device from the registry therefore brings the second factor back.
 *
 * @internal
 */
final class TrustedDeviceManager implements TrustedDeviceManagerInterface
{
    public function __construct(
        private readonly UserResolver $users,
        private readonly TrustedDeviceRepository $repository,
        private readonly DeviceCookies $cookies,
        private readonly RequestStack $requestStack,
        private readonly string $trustedLifetime,
    ) {
    }

    public function canSetTrustedDevice(object $user, Request $request, string $firewallName): bool
    {
        return $this->cookies->isWatched($firewallName) && null !== $this->users->reference($user);
    }

    public function addTrustedDevice(object $user, string $firewallName): void
    {
        $request = $this->requestStack->getMainRequest();
        $reference = $this->users->reference($user);
        $token = null === $request ? null : $this->cookies->current($request, $firewallName);
        if (null === $reference || null === $token) {
            return;
        }

        $now = new \DateTimeImmutable();
        $id = $this->repository->findActiveId($reference, $firewallName, hash('sha256', $token), $now);
        if (null !== $id) {
            $this->repository->verify($id, $now);
        }
    }

    public function isTrustedDevice(object $user, string $firewallName): bool
    {
        $request = $this->requestStack->getMainRequest();
        $reference = $this->users->reference($user);
        $token = null === $request ? null : $this->cookies->token($request, $firewallName);
        if (null === $reference || null === $token) {
            return false;
        }

        $now = new \DateTimeImmutable();

        // Counted from the verification, not from the last login: a device in regular use is asked for the second factor again.
        return $this->repository->isVerified($reference, $firewallName, hash('sha256', $token), $now, $now->modify('-'.$this->trustedLifetime));
    }
}
