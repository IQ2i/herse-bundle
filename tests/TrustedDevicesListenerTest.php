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

namespace IQ2i\HerseBundle\Tests;

use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The login events a real login does not produce in the test application: remember-me, token authenticators, unknown users.
 */
final class TrustedDevicesListenerTest extends TrustedDevicesKernelTestCase
{
    public function testARememberMeLoginNeverRegistersADevice(): void
    {
        $account = $this->account();

        $this->dispatch($account, $this->rememberMe());

        self::assertSame([], $this->devices());
        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
    }

    public function testARememberMeLoginRefreshesAKnownDevice(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->resetMails();

        $this->dispatch($account, $this->rememberMe(), Request::create('/', 'GET', [], ['herse_device_main' => $token], [], ['REMOTE_ADDR' => '198.51.100.9']));

        $devices = $this->devices();
        self::assertCount(1, $devices);
        self::assertSame('198.51.100.9', $devices[0]->getLastIp());
        self::assertSame([], $this->mails());
    }

    public function testACookieThatMatchesNoDeviceDoesNotMakeARememberMeLoginRegisterOne(): void
    {
        $account = $this->account();

        $this->dispatch($account, $this->rememberMe(), Request::create('/', 'GET', [], ['herse_device_main' => str_repeat('a', 64)]));

        self::assertSame([], $this->devices());
    }

    public function testANonInteractiveAuthenticatorIsIgnored(): void
    {
        $account = $this->account();
        $authenticator = $this->createMock(InteractiveAuthenticatorInterface::class);
        $authenticator->method('isInteractive')->willReturn(false);

        $this->dispatch($account, $authenticator);

        self::assertSame([], $this->devices());
    }

    public function testAnAuthenticatorThatIsNotInteractiveIsIgnored(): void
    {
        $this->dispatch($this->account(), $this->createMock(AuthenticatorInterface::class));

        self::assertSame([], $this->devices());
    }

    public function testAUserOfAnUndeclaredClassIsIgnored(): void
    {
        $this->dispatch(new InMemoryUser('root', null), $this->interactive());

        self::assertSame([], $this->devices());
        self::assertSame([], $this->logger()->messages('error'));
    }

    public function testNothingIsSentWithoutAnEmailAddress(): void
    {
        $account = $this->account();
        $account->setEmail('');

        $this->dispatch($account, $this->interactive());

        self::assertCount(1, $this->devices());
        self::assertSame([], $this->mails());
        self::assertCount(1, $this->events);
        self::assertNull($this->events[0]->getEmail());
        self::assertStringContainsString('No email address is available', $this->logger()->messages('warning')[0]);
    }

    public function testAnInteractiveLoginOnAWatchedFirewallRegistersTheDevice(): void
    {
        $this->dispatch($this->account(), $this->interactive());

        self::assertCount(1, $this->devices());
        self::assertCount(1, $this->mails());
    }

    private function dispatch(UserInterface $user, AuthenticatorInterface $authenticator, ?Request $request = null): void
    {
        $passport = new SelfValidatingPassport(new UserBadge('user', static fn (): UserInterface => $user));
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->service(TrustedDeviceListener::class, 'iq2i_herse.trusted_devices.listener')
            ->onLoginSuccess(new LoginSuccessEvent($authenticator, $passport, $token, $request ?? Request::create('/'), null, 'main'));
    }

    private function interactive(): InteractiveAuthenticatorInterface
    {
        $authenticator = $this->createMock(InteractiveAuthenticatorInterface::class);
        $authenticator->method('isInteractive')->willReturn(true);

        return $authenticator;
    }

    private function rememberMe(): RememberMeAuthenticator
    {
        $authenticator = $this->createMock(RememberMeAuthenticator::class);
        $authenticator->method('isInteractive')->willReturn(true);

        return $authenticator;
    }
}
