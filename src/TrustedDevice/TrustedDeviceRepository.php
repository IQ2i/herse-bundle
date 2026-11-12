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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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

    /**
     * The device of a cookie token, null when the user has none on the firewall or when it has expired.
     */
    public function findActiveId(UserReference $user, string $firewall, string $tokenHash, \DateTimeImmutable $now): ?int
    {
        return $this->activeId($user, $firewall, $tokenHash, $now, null);
    }

    /**
     * Whether the device passed a second factor after the given date.
     */
    public function isVerified(UserReference $user, string $firewall, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $verifiedAfter): bool
    {
        return null !== $this->activeId($user, $firewall, $tokenHash, $now, $verifiedAfter);
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

        // The rows are written without the unit of work: the entities it already holds are refreshed.
        /** @var list<TrustedDevice> $devices */
        $devices = $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();

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

    /**
     * The writes go straight to the database, never through a flush: a flush during a login would also write the
     * pending changes of the project, and a failure would close its entity manager.
     */
    public function insert(TrustedDevice $device): void
    {
        $metadata = $this->entityManager->getClassMetadata(TrustedDevice::class);
        $connection = $this->entityManager->getConnection();
        $row = $types = [];

        foreach ($metadata->getFieldNames() as $field) {
            $column = $metadata->getColumnName($field);
            if ($metadata->isIdentifier($field)) {
                // Platforms without identity columns (PostgreSQL with ORM 2) take the identifier from a sequence.
                $sequence = $metadata->sequenceGeneratorDefinition['sequenceName'] ?? null;
                if ($metadata->isIdGeneratorSequence() && \is_string($sequence)) {
                    $row[$column] = $connection->fetchOne($connection->getDatabasePlatform()->getSequenceNextValSQL($sequence));
                }

                continue;
            }

            $value = $metadata->getFieldValue($device, $field);
            $row[$column] = $value instanceof \BackedEnum ? $value->value : $value;
            $types[$column] = $metadata->getTypeOfField($field) ?? Types::STRING;
        }

        $connection->insert($metadata->getTableName(), $row, $types);
    }

    public function touch(int $id, \DateTimeImmutable $now, ?string $ip, \DateTimeImmutable $expiresAt): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(TrustedDevice::class, 'd')
            ->set('d.lastUsedAt', ':now')
            ->set('d.lastIp', ':ip')
            ->set('d.expiresAt', ':expiresAt')
            ->where('d.id = :id')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('ip', $ip)
            ->setParameter('expiresAt', $expiresAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->getQuery()
            ->execute()
        ;
    }

    public function verify(int $id, \DateTimeImmutable $now): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(TrustedDevice::class, 'd')
            ->set('d.trustLevel', ':level')
            ->set('d.verifiedAt', ':now')
            ->where('d.id = :id')
            ->setParameter('level', TrustLevel::Verified->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->getQuery()
            ->execute()
        ;
    }

    private function activeId(UserReference $user, string $firewall, string $tokenHash, \DateTimeImmutable $now, ?\DateTimeImmutable $verifiedAfter): ?int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('d.id')
            ->from(TrustedDevice::class, 'd')
            ->where('d.userType = :type AND d.userIdentifier = :identifier AND d.firewall = :firewall AND d.tokenHash = :hash AND d.expiresAt > :now')
            ->setParameter('type', $user->type)
            ->setParameter('identifier', $user->identifier)
            ->setParameter('firewall', $firewall)
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)
        ;

        if (null !== $verifiedAfter) {
            $query->andWhere('d.trustLevel = :level AND d.verifiedAt > :verifiedAfter')
                ->setParameter('level', TrustLevel::Verified->value)
                ->setParameter('verifiedAfter', $verifiedAfter, Types::DATETIME_IMMUTABLE)
            ;
        }

        $id = $query->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return is_numeric($id) ? (int) $id : null;
    }
}
