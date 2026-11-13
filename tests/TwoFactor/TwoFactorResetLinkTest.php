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

namespace IQ2i\HerseBundle\Tests\TwoFactor;

use IQ2i\HerseBundle\Tests\Security\FirewallRegistries;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use IQ2i\HerseBundle\Twig\HerseExtension;
use IQ2i\HerseBundle\TwoFactor\TwoFactorResetLink;
use IQ2i\HerseBundle\User\UserResolver;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class TwoFactorResetLinkTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }
    }

    public function testTheLinkLeadsToTheResetScreenAndComesBackToTheCurrentPage(): void
    {
        self::assertSame('/admin/second-factor/reset/customer/7?back=/admin?crudAction%3Dindex%26page%3D2', self::link()->path(self::account()));
        self::assertSame('/admin/second-factor/reset/customer/7?back=/admin/users', self::link()->path(self::account(), '/admin/users'));
    }

    public function testThereIsNoLinkWhenThereIsNothingToReset(): void
    {
        self::assertNull(self::link()->path(self::account(secret: null)));
        self::assertNull(self::link()->path(new EmailAccount('alice@example.com')));
        self::assertNull(self::link()->path(null));
    }

    public function testThereIsNoLinkForAUserOfAnUndeclaredClassOrOnAnUndeclaredFirewall(): void
    {
        self::assertNull(self::link(users: [])->path(self::account()));
        self::assertNull(self::link(firewall: 'api')->path(self::account()));
    }

    public function testThereIsNoLinkWithoutTheRole(): void
    {
        self::assertNull(self::link(granted: false)->path(self::account()));
    }

    public function testTheLinkIsAvailableInTheTemplates(): void
    {
        self::assertSame('/admin/second-factor/reset/customer/7?back=/admin/users', (new HerseExtension(null, null, 'layout.html.twig', self::link()))->twoFactorResetPath(self::account(), '/admin/users'));
        self::assertNull((new HerseExtension(null, null, 'layout.html.twig'))->twoFactorResetPath(self::account()));
    }

    public static function account(?string $secret = 'JBSWY3DPEHPK3PXP'): TotpAccount
    {
        $account = new TotpAccount('alice@example.com');
        $account->setTotpSecret($secret);
        (new \ReflectionProperty(TotpAccount::class, 'id'))->setValue($account, 7);

        return $account;
    }

    /**
     * A link used from "/admin?crudAction=index&page=2", by a user who has the role or not.
     *
     * @param array<class-string, array{type: string, email: string, id: string}>|null $users the declared user classes
     */
    public static function link(string $firewall = 'admin', bool $granted = true, ?array $users = null): TwoFactorResetLink
    {
        $routes = new RouteCollection();
        $routes->add('iq2i_herse_admin_two_factor_reset', new Route('/admin/second-factor/reset/{type}/{id}'));
        $requests = new RequestStack();
        $requests->push(Request::create('/admin?crudAction=index&page=2'));
        $container = new Container();
        $container->set('security.authorization_checker', new class($granted) implements AuthorizationCheckerInterface {
            public function __construct(private readonly bool $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                return $this->granted && 'ROLE_ADMIN' === $attribute;
            }
        });

        return new TwoFactorResetLink(
            FirewallRegistries::create(['admin' => ['path_prefix' => '/admin']], $firewall),
            new UserResolver($users ?? [TotpAccount::class => ['type' => 'customer', 'email' => 'email', 'id' => 'id']], PropertyAccess::createPropertyAccessor()),
            new Security($container),
            new UrlGenerator($routes, new RequestContext()),
            $requests,
            'ROLE_ADMIN',
        );
    }
}
