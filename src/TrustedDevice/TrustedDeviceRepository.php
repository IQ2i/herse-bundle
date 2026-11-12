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

use Doctrine\ORM\EntityManagerInterface;
use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\User\UserReference;

/**
 * @internal
 */
final class TrustedDeviceRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findOne(UserReference $user, string $firewall, string $tokenHash): ?TrustedDevice
    {
        $device = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(TrustedDevice::class, 'd')
            ->where('d.userType = :type AND d.userIdentifier = :identifier AND d.firewall = :firewall AND d.tokenHash = :hash')
            ->setParameter('type', $user->type)
            ->setParameter('identifier', $user->identifier)
            ->setParameter('firewall', $firewall)
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        \assert(null === $device || $device instanceof TrustedDevice);

        return $device;
    }

    /**
     * The identity of the devices of a user, without hydrating entities: the rows can then be deleted with DQL
     * without leaving stale objects in the unit of work.
     *
     * @return list<array{id: int, firewall: string, tokenHash: string}>
     */
    public function findIdentities(UserReference $user, ?int $id = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('d.id AS id', 'd.firewall AS firewall', 'd.tokenHash AS tokenHash')
            ->from(TrustedDevice::class, 'd')
            ->where('d.userType = :type AND d.userIdentifier = :identifier')
            ->setParameter('type', $user->type)
            ->setParameter('identifier', $user->identifier)
        ;

        if (null !== $id) {
            $query->andWhere('d.id = :id')->setParameter('id', $id);
        }

        /** @var list<array{id: int, firewall: string, tokenHash: string}> $identities */
        $identities = $query->getQuery()->getArrayResult();

        return $identities;
    }

    /**
     * The devices that have not expired, the most recently used first.
     *
     * @return list<TrustedDevice>
     */
    public function findActive(UserReference $user, ?string $firewall = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(TrustedDevice::class, 'd')
            ->where('d.userType = :type AND d.userIdentifier = :identifier AND d.expiresAt > :now')
            ->orderBy('d.lastUsedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setParameter('type', $user->type)
            ->setParameter('identifier', $user->identifier)
            ->setParameter('now', new \DateTimeImmutable())
        ;

        if (null !== $firewall) {
            $query->andWhere('d.firewall = :firewall')->setParameter('firewall', $firewall);
        }

        /** @var list<TrustedDevice> $devices */
        $devices = $query->getQuery()->getResult();

        return $devices;
    }

    public function hasAny(UserReference $user, string $firewall): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('d.id')
            ->from(TrustedDevice::class, 'd')
            ->where('d.userType = :type AND d.userIdentifier = :identifier AND d.firewall = :firewall')
            ->setParameter('type', $user->type)
            ->setParameter('identifier', $user->identifier)
            ->setParameter('firewall', $firewall)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function countExpired(): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(TrustedDevice::class, 'd')
            ->where('d.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult()
        ;
        \assert(is_numeric($count));

        return (int) $count;
    }

    /**
     * Deletes with DQL, outside of the unit of work: it is safe to call while a flush is ending.
     */
    public function deleteExpired(?UserReference $user = null): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->delete(TrustedDevice::class, 'd')
            ->where('d.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
        ;

        if (null !== $user) {
            $query->andWhere('d.userType = :type AND d.userIdentifier = :identifier')
                ->setParameter('type', $user->type)
                ->setParameter('identifier', $user->identifier)
            ;
        }

        $deleted = $query->getQuery()->execute();
        \assert(\is_int($deleted));

        return $deleted;
    }

    /**
     * @param list<int> $ids
     */
    public function deleteByIds(UserReference $user, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $deleted = $this->entityManager->createQueryBuilder()
            ->delete(TrustedDevice::class, 'd')
            ->where('d.id IN (:ids) AND d.userType = :type AND d.userIdentifier = :identifier')
            ->setParameter('ids', $ids)
            ->setParameter('type', $user->type)
            ->setParameter('identifier', $user->identifier)
            ->getQuery()
            ->execute()
        ;
        \assert(\is_int($deleted));

        return $deleted;
    }

    public function persist(TrustedDevice $device): void
    {
        $this->entityManager->persist($device);
        $this->entityManager->flush();
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }
}
