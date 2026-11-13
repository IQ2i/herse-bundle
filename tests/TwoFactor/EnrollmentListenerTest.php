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
use IQ2i\HerseBundle\TwoFactor\EnrollmentListener;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class EnrollmentListenerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }
    }

    public function testAUserWithoutAuthenticatorAppIsSentToTheEnrollmentScreen(): void
    {
        self::assertSame('/admin/second-factor/setup', $this->redirection($this->token(new TotpAccount('alice@example.com'))));
    }

    public function testAnEnrolledUserGoesOn(): void
    {
        $account = new TotpAccount('alice@example.com');
        $account->setTotpSecret('JBSWY3DPEHPK3PXP');

        self::assertNull($this->redirection($this->token($account)));
    }

    public function testTheEnrollmentScreenAndTheDevelopmentToolsStayReachable(): void
    {
        $token = $this->token(new TotpAccount('alice@example.com'));

        self::assertNull($this->redirection($token, route: 'iq2i_herse_admin_two_factor_setup'));
        self::assertNull($this->redirection($token, route: '_wdt'));
        self::assertNotNull($this->redirection($token, route: null));
    }

    public function testNobodyIsRedirectedOutsideOfTheFirewallsAskingTheSecondFactor(): void
    {
        $token = $this->token(new TotpAccount('alice@example.com'));

        self::assertNull($this->redirection($token, firewall: 'api'));
        self::assertNull($this->redirection($token, firewall: 'shop'));
        self::assertNull($this->redirection($token, required: false));
    }

    public function testAVisitorAndAUserOfAnotherKindGoOn(): void
    {
        self::assertNull($this->redirection(null));
        self::assertNull($this->redirection($this->token(new EmailAccount('alice@example.com'))));
    }

    public function testAPendingSecondFactorIsLeftToScheb(): void
    {
        $account = new TotpAccount('alice@example.com');

        self::assertNull($this->redirection(new TwoFactorToken($this->token($account), null, 'admin', ['totp'])));
    }

    public function testSomebodyImpersonatingAUserIsNeverSentToEnrollADevice(): void
    {
        $account = new TotpAccount('alice@example.com');

        self::assertNull($this->redirection(new SwitchUserToken($account, 'admin', ['ROLE_USER'], $this->token(new TotpAccount('admin@example.com')))));
    }

    public function testASubRequestAndAnAnsweredRequestAreLeftAlone(): void
    {
        $token = $this->token(new TotpAccount('alice@example.com'));

        self::assertNull($this->redirection($token, type: HttpKernelInterface::SUB_REQUEST));
        self::assertNull($this->redirection($token, answered: true));
    }

    private function token(UserInterface $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'admin', ['ROLE_USER']);
    }

    /**
     * @return string|null where the listener sends the request going through the firewall, null when it lets it go on
     */
    private function redirection(?TokenInterface $token, string $firewall = 'admin', ?string $route = 'dashboard', bool $required = true, int $type = HttpKernelInterface::MAIN_REQUEST, bool $answered = false): ?string
    {
        $routes = new RouteCollection();
        $routes->add('iq2i_herse_admin_two_factor_setup', new Route('/admin/second-factor/setup'));
        $tokens = new TokenStorage();
        $tokens->setToken($token);
        $registry = FirewallRegistries::create(['admin' => ['path_prefix' => '/admin'], 'shop' => ['two_factor' => false]], $firewall, $required);

        $request = Request::create('/admin/dashboard');
        $request->attributes->set('_route', $route);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
        $response = new Response();
        if ($answered) {
            $event->setResponse($response);
        }

        (new EnrollmentListener($registry, $tokens, new UrlGenerator($routes, new RequestContext())))->onKernelRequest($event);

        return $event->getResponse() === ($answered ? $response : null) ? null : $event->getResponse()?->headers->get('Location');
    }
}
