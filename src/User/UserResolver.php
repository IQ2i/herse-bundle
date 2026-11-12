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

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Reads the declared user classes (iq2i_herse.users) to turn a project user object into a reference, an email address or a locale.
 *
 * @internal
 */
final class UserResolver
{
    /**
     * @param array<class-string, array{type: string, email: string, id: string, password?: string, locale?: string|null}> $users
     */
    public function __construct(
        private readonly array $users,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function reference(object $user): ?UserReference
    {
        $config = $this->config($user);
        if (null === $config) {
            return null;
        }

        $identifier = $this->propertyAccessor->getValue($user, $config['id']);
        if (!\is_scalar($identifier) && !$identifier instanceof \Stringable) {
            throw new \LogicException(\sprintf('The "%s" property of "%s" must be a scalar or a Stringable to be used as the user identifier.', $config['id'], $user::class));
        }

        return new UserReference($config['type'], (string) $identifier);
    }

    public function email(object $user): ?string
    {
        $config = $this->config($user);

        return null === $config ? null : $this->readString($user, $config['email']);
    }

    public function locale(object $user): ?string
    {
        $path = $this->config($user)['locale'] ?? null;

        return null === $path ? null : $this->readString($user, $path);
    }

    public function passwordProperty(object $user): ?string
    {
        $config = $this->config($user);

        return null === $config ? null : ($config['password'] ?? 'password');
    }

    /**
     * @return array{type: string, email: string, id: string, password?: string, locale?: string|null}|null
     */
    private function config(object $user): ?array
    {
        foreach ($this->users as $class => $config) {
            if ($user instanceof $class) {
                return $config;
            }
        }

        return null;
    }

    private function readString(object $user, string $path): ?string
    {
        $value = $this->propertyAccessor->getValue($user, $path);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
