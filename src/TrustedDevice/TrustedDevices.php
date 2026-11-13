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

namespace IQ2i\HerseBundle\TrustedDevice;

use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Event\TrustedDevicesRevokedEvent;
use IQ2i\HerseBundle\User\UserReference;
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Lists and revokes the trusted devices of a user. This is the only way for a project to remove devices:
 * the revocation also takes care of the cookie of the current browser.
 *
 * Revoking a device removes the trust, it does not end the session in progress.
 */
final class TrustedDevices
{
    /**
     * @internal
     */
    public function __construct(
        private readonly UserResolver $users,
        private readonly TrustedDeviceRepository $repository,
        private readonly DeviceCookies $cookies,
        private readonly RequestStack $requestStack,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * The devices that have not expired, the most recently used first.
     *
     * @return list<TrustedDevice>
     */
    public function list(object $user, ?string $firewall = null): array
    {
        return $this->repository->findActive($this->reference($user), $firewall);
    }

    /**
     * Whether the device is the one of the current request.
     */
    public function isCurrent(TrustedDevice $device): bool
    {
        return $this->isCurrentToken($device->getFirewall(), $device->getTokenHash());
    }

    /**
     * @return bool false when the user has no such device
     */
    public function revoke(object $user, int $deviceId): bool
    {
        $reference = $this->reference($user);

        return $this->delete($reference, $this->repository->findIdentities($reference, $deviceId), false) > 0;
    }

    /**
     * @return int the number of devices revoked
     */
    public function revokeAll(object $user, bool $keepCurrent = false): int
    {
        $reference = $this->reference($user);

        return $this->delete($reference, $this->repository->findIdentities($reference), $keepCurrent);
    }

    /**
     * @param list<array{id: int, firewall: string, tokenHash: string}> $devices
     */
    private function delete(UserReference $reference, array $devices, bool $keepCurrent): int
    {
        $ids = [];
        foreach ($devices as $device) {
            if ($this->isCurrentToken($device['firewall'], $device['tokenHash'])) {
                if ($keepCurrent) {
                    continue;
                }
                $this->cookies->clear($device['firewall']);
            }
            $ids[] = $device['id'];
        }

        $count = $this->repository->deleteByIds($reference, $ids);
        if ($count > 0) {
            $this->dispatcher?->dispatch(new TrustedDevicesRevokedEvent($reference, $count));
        }

        return $count;
    }

    private function isCurrentToken(string $firewall, string $tokenHash): bool
    {
        $request = $this->requestStack->getMainRequest();
        $token = null === $request ? null : $this->cookies->token($request, $firewall);

        return null !== $token && hash_equals($tokenHash, hash('sha256', $token));
    }

    private function reference(object $user): UserReference
    {
        return $this->users->reference($user) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a user class declared in iq2i_herse.users.', $user::class));
    }
}
