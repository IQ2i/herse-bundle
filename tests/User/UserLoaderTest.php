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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use IQ2i\HerseBundle\Tests\TestApplication\User;
use IQ2i\HerseBundle\User\UserLoader;
use PHPUnit\Framework\TestCase;

final class UserLoaderTest extends TestCase
{
    public function testAnEmailPathThatIsNotMadeOfFieldsCannotBeSearched(): void
    {
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($this->createStub(EntityManagerInterface::class));
        $loader = new UserLoader([User::class => ['type' => 'shop', 'email' => 'emails[0]', 'id' => 'id']], $doctrine);

        $this->expectExceptionMessage('The email path "emails[0]" of "'.User::class.'" cannot be searched in the database.');

        $loader->findByEmail('alice@example.com');
    }

    public function testAClassWithoutEntityManagerIsSkipped(): void
    {
        $loader = new UserLoader([User::class => ['type' => 'shop', 'email' => 'email', 'id' => 'id']], $this->createStub(ManagerRegistry::class));

        self::assertSame([], $loader->findByEmail('alice@example.com'));
        self::assertNull($loader->find('shop', '1'));
        self::assertNull($loader->find('admin', '1'));
    }
}
