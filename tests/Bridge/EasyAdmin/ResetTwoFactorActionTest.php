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

namespace IQ2i\HerseBundle\Tests\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use IQ2i\HerseBundle\Bridge\EasyAdmin\ResetTwoFactorAction;
use IQ2i\HerseBundle\Tests\Security\FirewallRegistries;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use IQ2i\HerseBundle\User\UserResolver;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Translator;

final class ResetTwoFactorActionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Action::class) || !class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('easycorp/easyadmin-bundle and scheb/2fa-bundle are not installed.');
        }
    }

    public function testTheActionLinksToTheConfirmationScreenAndComesBack(): void
    {
        $action = $this->action()->create()->getAsDto();
        $url = $action->getUrl();

        self::assertSame(ResetTwoFactorAction::NAME, $action->getName());
        self::assertSame('Reset two-factor', $action->getLabel());
        self::assertIsCallable($url);
        self::assertSame('/admin/second-factor/reset/customer/7?back=/admin?crudAction%3Dindex%26page%3D2', $url($this->account()));
    }

    public function testTheLabelIsTranslated(): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $translator->addResource('array', ['Reset two-factor' => 'Réinitialiser la 2FA'], 'fr', 'iq2i_herse');

        self::assertSame('Réinitialiser la 2FA', $this->action(translator: $translator)->create()->getAsDto()->getLabel());
    }

    public function testTheActionIsOnlyShownOnTheUsersHavingAnAuthenticatorApp(): void
    {
        $action = $this->action();

        self::assertTrue($action->isDisplayedFor($this->account()));
        self::assertFalse($action->isDisplayedFor($this->account(secret: null)));
        self::assertFalse($action->isDisplayedFor(new EmailAccount('alice@example.com')));
        self::assertFalse($action->isDisplayedFor(null));
    }

    public function testTheActionNeedsADeclaredFirewallAndADeclaredUserClass(): void
    {
        try {
            $this->action(firewall: 'api')->url($this->account());
            self::fail('The URL was generated.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('not declared in iq2i_herse.firewalls', $exception->getMessage());
        }

        $this->expectExceptionMessage('is not declared in iq2i_herse.users');
        $this->action()->url(new EmailAccount('alice@example.com'));
    }

    private function account(?string $secret = 'JBSWY3DPEHPK3PXP'): TotpAccount
    {
        $account = new TotpAccount('alice@example.com');
        $account->setTotpSecret($secret);
        (new \ReflectionProperty(TotpAccount::class, 'id'))->setValue($account, 7);

        return $account;
    }

    private function action(string $firewall = 'admin', ?Translator $translator = null): ResetTwoFactorAction
    {
        $routes = new RouteCollection();
        $routes->add('iq2i_herse_admin_two_factor_reset', new Route('/admin/second-factor/reset/{type}/{id}'));
        $requests = new RequestStack();
        $requests->push(Request::create('/admin?crudAction=index&page=2'));

        return new ResetTwoFactorAction(
            FirewallRegistries::create(['admin' => ['path_prefix' => '/admin']], $firewall),
            new UserResolver([TotpAccount::class => ['type' => 'customer', 'email' => 'email', 'id' => 'id']], PropertyAccess::createPropertyAccessor()),
            new UrlGenerator($routes, new RequestContext()),
            $requests,
            $translator,
        );
    }
}
