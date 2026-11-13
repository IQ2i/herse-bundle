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

use IQ2i\HerseBundle\Event\TwoFactorResetEvent;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * An administrator removes the authenticator app of a user who lost it, from the screen of the bundle.
 */
final class TwoFactorResetTest extends TwoFactorKernelTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    /** @var list<TwoFactorResetEvent> */
    private array $resetEvents = [];

    protected static function environment(): string
    {
        return 'two_factor_screens_totp';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetEvents = [];
        $this->service(EventDispatcherInterface::class, 'test.event_dispatcher')->addListener(TwoFactorResetEvent::class, function (TwoFactorResetEvent $event): void {
            $this->resetEvents[] = $event;
        });
    }

    public function testTheAdministratorConfirmsThenTheUserEnrollsAgain(): void
    {
        $admin = $this->account('admin@example.com');
        $alice = $this->account('alice@example.com');
        $this->logInWithSecondFactor('admin@example.com');

        $screen = $this->send('GET', $this->resetPath($alice));
        $html = (string) $screen->getContent();
        self::assertSame(200, $screen->getStatusCode());
        self::assertStringContainsString('Reset the two-factor authentication of alice@example.com?', $html);
        self::assertTrue($this->reload($alice)->isTotpAuthenticationEnabled(), 'Nothing is reset before the confirmation.');

        $response = $this->send('POST', $this->resetPath($alice), ['_csrf_token' => $this->csrfToken($html)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertFalse($this->reload($alice)->isTotpAuthenticationEnabled());
        self::assertCount(1, $this->resetEvents);
        self::assertSame((string) $alice->getId(), $this->resetEvents[0]->reference?->identifier);
        self::assertInstanceOf(TotpAccount::class, $this->resetEvents[0]->by);
        self::assertSame($admin->getId(), $this->resetEvents[0]->by->getId());
    }

    #[DataProvider('provideBackPaths')]
    public function testTheScreenOnlyGoesBackToAPathOfTheSite(string $back, string $expected): void
    {
        $this->account('admin@example.com');
        $alice = $this->account('alice@example.com');
        $this->logInWithSecondFactor('admin@example.com');

        $html = (string) $this->send('GET', $this->resetPath($alice), ['back' => $back])->getContent();
        self::assertStringContainsString('<a href="'.htmlspecialchars($expected).'">', $html);

        $response = $this->send('POST', $this->resetPath($alice), ['_csrf_token' => $this->csrfToken($html), 'back' => $back]);

        self::assertSame($expected, $response->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideBackPaths(): iterable
    {
        yield 'a page of the site' => ['/admin/users?page=2&sort=email', '/admin/users?page=2&sort=email'];
        yield 'another site' => ['https://evil.example/', '/'];
        yield 'a protocol-relative address' => ['//evil.example/', '/'];
        yield 'a backslash some browsers read as a slash' => ['/\\evil.example/', '/'];
        yield 'not a path' => ['javascript:alert(1)', '/'];
    }

    public function testAnAccountWithoutAuthenticatorAppIsReportedAsSuch(): void
    {
        $this->account('admin@example.com');
        $bob = $this->account('bob@example.com', null);
        $this->logInWithSecondFactor('admin@example.com');

        self::assertStringContainsString('This account has no authenticator app yet.', (string) $this->send('GET', $this->resetPath($bob))->getContent());
    }

    public function testASubmissionWithoutTheCsrfTokenResetsNothing(): void
    {
        $this->account('admin@example.com');
        $alice = $this->account('alice@example.com');
        $this->logInWithSecondFactor('admin@example.com');

        self::assertSame(403, $this->send('POST', $this->resetPath($alice))->getStatusCode());
        self::assertTrue($this->reload($alice)->isTotpAuthenticationEnabled());
    }

    public function testAUserWithoutTheRoleIsRefused(): void
    {
        $alice = $this->account('alice@example.com');
        $this->account('bob@example.com');
        $this->logInWithSecondFactor('bob@example.com');

        self::assertSame(403, $this->send('GET', $this->resetPath($alice))->getStatusCode());
    }

    public function testAVisitorIsSentToTheLogin(): void
    {
        self::assertSame(401, $this->send('GET', $this->resetPath($this->account('alice@example.com')))->getStatusCode());
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $this->account('admin@example.com');
        $this->logInWithSecondFactor('admin@example.com');

        self::assertSame(404, $this->send('GET', '/second-factor/reset/customer/999')->getStatusCode());
        self::assertSame(404, $this->send('GET', '/second-factor/reset/unknown/1')->getStatusCode());
    }

    private function account(string $email, ?string $secret = self::SECRET): TotpAccount
    {
        $account = new TotpAccount($email, password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $account->setTotpSecret($secret);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function reload(TotpAccount $account): TotpAccount
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(TotpAccount::class, $account->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function resetPath(TotpAccount $account): string
    {
        return '/second-factor/reset/customer/'.$account->getId();
    }

    /**
     * The password, then the code of the authenticator app.
     */
    private function logInWithSecondFactor(string $email): Response
    {
        $this->signIn($email);
        $html = (string) $this->send('GET', '/2fa')->getContent();

        return $this->send('POST', '/2fa_check', ['_auth_code' => TOTP::createFromSecret(self::SECRET)->now(), '_csrf_token' => $this->csrfToken($html)]);
    }
}
