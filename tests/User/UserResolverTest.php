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

namespace IQ2i\HerseBundle\Tests\User;

use IQ2i\HerseBundle\Tests\TestApplication\User;
use IQ2i\HerseBundle\User\UserReference;
use IQ2i\HerseBundle\User\UserResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class UserResolverTest extends TestCase
{
    public function testAUserIsResolvedFromItsDeclaration(): void
    {
        $resolver = $this->resolver(['type' => 'shop', 'email' => 'email', 'id' => 'id', 'password' => 'plainPassword', 'locale' => 'plainPassword']);
        $user = new User(42, 'user@example.com', 'fr');

        $reference = $resolver->reference($user);

        self::assertNotNull($reference);
        self::assertSame(['shop', '42'], [$reference->type, $reference->identifier]);
        self::assertSame('user@example.com', $resolver->email($user));
        self::assertSame('fr', $resolver->locale($user));
        self::assertSame('plainPassword', $resolver->passwordProperty($user));
    }

    public function testTwoReferencesAreEqualWhenTheirTypeAndIdentifierAre(): void
    {
        $reference = new UserReference('shop', '42');

        self::assertTrue($reference->equals(new UserReference('shop', '42')));
        self::assertFalse($reference->equals(new UserReference('admin', '42')));
        self::assertFalse($reference->equals(new UserReference('shop', '43')));
    }

    public function testTheLocaleIsNullWhenNoPropertyIsDeclared(): void
    {
        self::assertNull($this->resolver(['type' => 'shop', 'email' => 'email', 'id' => 'id'])->locale(new User()));
    }

    public function testTheLocaleAndTheEmailAreNullWhenEmpty(): void
    {
        $resolver = $this->resolver(['type' => 'shop', 'email' => 'email', 'id' => 'id', 'locale' => 'plainPassword']);

        self::assertNull($resolver->email(new User(1, '')));
        self::assertNull($resolver->locale(new User(1, 'user@example.com', '')));
        self::assertNull($resolver->locale(new User(1, 'user@example.com')));
    }

    public function testThePasswordPropertyDefaultsToPassword(): void
    {
        self::assertSame('password', $this->resolver(['type' => 'shop', 'email' => 'email', 'id' => 'id'])->passwordProperty(new User()));
    }

    public function testAnUndeclaredUserResolvesToNothing(): void
    {
        $resolver = new UserResolver([], PropertyAccess::createPropertyAccessor());
        $user = new User();

        self::assertNull($resolver->reference($user));
        self::assertNull($resolver->email($user));
        self::assertNull($resolver->locale($user));
        self::assertNull($resolver->passwordProperty($user));
    }

    public function testAnIdentifierThatCannotBeAStringIsRejected(): void
    {
        $resolver = $this->resolver(['type' => 'shop', 'email' => 'email', 'id' => 'roles']);

        $this->expectExceptionMessage('The "roles" property of "'.User::class.'" must be a scalar or a Stringable');

        $resolver->reference(new User());
    }

    /**
     * @param array{type: string, email: string, id: string, password?: string, locale?: string|null} $config
     */
    private function resolver(array $config): UserResolver
    {
        return new UserResolver([User::class => $config], PropertyAccess::createPropertyAccessor());
    }
}
