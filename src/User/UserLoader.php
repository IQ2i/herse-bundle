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

namespace IQ2i\HerseBundle\User;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Finds the users of the declared classes (iq2i_herse.users) in the database.
 *
 * @internal
 */
final class UserLoader
{
    /**
     * @param array<class-string, array{type: string, email: string, id: string}> $users
     */
    public function __construct(
        private readonly array $users,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function find(string $type, string $identifier): ?object
    {
        foreach ($this->users as $class => $config) {
            if ($type === $config['type']) {
                return $this->doctrine->getManagerForClass($class)?->getRepository($class)->findOneBy([$config['id'] => $identifier]);
            }
        }

        return null;
    }

    /**
     * @param string|null $type only the users of this type
     *
     * @return array<string, object> the users having the email address, by type
     */
    public function findByEmail(string $email, ?string $type = null): array
    {
        $found = [];
        foreach ($this->users as $class => $config) {
            $manager = $this->doctrine->getManagerForClass($class);
            if ((null !== $type && $type !== $config['type']) || !$manager instanceof EntityManagerInterface) {
                continue;
            }

            // The path may go through associations, e.g. "customer.email": each one is joined.
            $segments = explode('.', $config['email']);
            if ([] !== preg_grep('/^\w+$/', $segments, \PREG_GREP_INVERT)) {
                throw new \LogicException(\sprintf('The email path "%s" of "%s" cannot be searched in the database.', $config['email'], $class));
            }

            $field = array_pop($segments);
            $query = $manager->createQueryBuilder()->select('u')->from($class, 'u');
            $alias = 'u';
            foreach ($segments as $index => $association) {
                $query->join($alias.'.'.$association, $alias = 'a'.$index);
            }

            $user = $query->andWhere($alias.'.'.$field.' = :email')->setParameter('email', $email)->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if (\is_object($user)) {
                $found[$config['type']] = $user;
            }
        }

        return $found;
    }
}
