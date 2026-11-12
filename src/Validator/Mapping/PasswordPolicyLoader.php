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

namespace IQ2i\HerseBundle\Validator\Mapping;

use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\Loader\LoaderInterface;

/**
 * Applies the password policy to the plain password property of the declared user classes.
 */
final class PasswordPolicyLoader implements LoaderInterface
{
    /**
     * @param array<class-string, array{property: string, groups: list<string>}> $mapping
     */
    public function __construct(private readonly array $mapping)
    {
    }

    public function loadClassMetadata(ClassMetadata $metadata): bool
    {
        $target = $this->mapping[$metadata->getClassName()] ?? null;
        if (null === $target) {
            return false;
        }

        $metadata->addPropertyConstraint($target['property'], new PasswordPolicy(groups: $target['groups']));

        return true;
    }
}
