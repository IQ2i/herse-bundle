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

namespace IQ2i\HerseBundle\Tests\Validator\Mapping;

use IQ2i\HerseBundle\Tests\TestApplication\User;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Mapping\ClassMetadata;

final class PasswordPolicyLoaderTest extends TestCase
{
    public function testTheConstraintIsAddedToTheConfiguredProperty(): void
    {
        $metadata = new ClassMetadata(User::class);

        $loaded = (new PasswordPolicyLoader([User::class => ['property' => 'plainPassword', 'groups' => ['sylius']]]))->loadClassMetadata($metadata);

        self::assertTrue($loaded);
        $constraints = $metadata->getPropertyMetadata('plainPassword')[0]->getConstraints();
        self::assertCount(1, $constraints);
        self::assertInstanceOf(PasswordPolicy::class, $constraints[0]);
        self::assertSame(['sylius'], $constraints[0]->groups);
    }

    public function testAnotherClassIsIgnored(): void
    {
        $metadata = new ClassMetadata(\stdClass::class);

        self::assertFalse((new PasswordPolicyLoader([User::class => ['property' => 'plainPassword', 'groups' => ['Default']]]))->loadClassMetadata($metadata));
        self::assertSame([], $metadata->getConstrainedProperties());
    }
}
