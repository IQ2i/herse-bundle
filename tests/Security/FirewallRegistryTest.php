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

namespace IQ2i\HerseBundle\Tests\Security;

use IQ2i\HerseBundle\Security\FirewallRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RequestStack;

final class FirewallRegistryTest extends TestCase
{
    public function testThereIsNoCurrentFirewallOutsideOfARequest(): void
    {
        $registry = new FirewallRegistry(['main' => ['path_prefix' => '', 'layout' => 'layout.html.twig']], new Security(new Container()), new RequestStack());

        self::assertNull($registry->current());
        self::assertNull($registry->layout());
    }
}
