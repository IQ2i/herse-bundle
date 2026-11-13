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

use IQ2i\HerseBundle\Event\TwoFactorEnabledEvent;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use OTPHP\TOTP;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second factor is mandatory: an account without authenticator app enrolls one on the screen of the bundle.
 */
final class TwoFactorEnrollmentTest extends TwoFactorKernelTestCase
{
    /** @var list<TwoFactorEnabledEvent> */
    private array $enabledEvents = [];

    protected static function environment(): string
    {
        return 'two_factor_screens_totp';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->enabledEvents = [];
        $this->service(EventDispatcherInterface::class, 'test.event_dispatcher')->addListener(TwoFactorEnabledEvent::class, function (TwoFactorEnabledEvent $event): void {
            $this->enabledEvents[] = $event;
        });
    }

    public function testAnAccountWithoutAuthenticatorAppIsSentToTheEnrollmentScreen(): void
    {
        $this->account();
        $this->signIn();

        $response = $this->visitPrivatePage();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/second-factor/setup', $response->headers->get('Location'));
    }

    public function testTheScreenShowsTheQrCodeAndTheKey(): void
    {
        $this->account();
        $this->signIn();

        $screen = $this->send('GET', '/second-factor/setup');
        $html = (string) $screen->getContent();

        self::assertSame(200, $screen->getStatusCode());
        self::assertStringContainsString('<title>Set up two-factor authentication</title>', $html);
        self::assertStringContainsString('<img src="data:image/svg+xml;base64,', $html);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $this->shownSecret($html));
        self::assertStringContainsString('href="/logout"', $html);
        self::assertStringNotContainsString('name="_trusted"', $html);
        // The same secret is shown again until it is confirmed.
        self::assertSame($this->shownSecret($html), $this->shownSecret((string) $this->send('GET', '/second-factor/setup')->getContent()));
    }

    public function testAFirstCodeStoresTheSecretAndOpensTheApplication(): void
    {
        $account = $this->account();
        $this->signIn();
        $html = (string) $this->send('GET', '/second-factor/setup')->getContent();
        $secret = $this->shownSecret($html);

        $response = $this->submitSetup($html, TOTP::createFromSecret($secret)->now());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        $this->assertLoggedIn();
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(TotpAccount::class, $account->getId());
        self::assertSame($secret, $stored?->getTotpAuthenticationConfiguration()?->getSecret());

        self::assertCount(1, $this->enabledEvents);
        self::assertSame((string) $account->getId(), $this->enabledEvents[0]->reference?->identifier);
        self::assertSame('main', $this->enabledEvents[0]->firewall);
        self::assertSame('203.0.113.7', $this->enabledEvents[0]->ip);
    }

    public function testAWrongCodeStoresNothing(): void
    {
        $account = $this->account();
        $this->signIn();
        $html = (string) $this->send('GET', '/second-factor/setup')->getContent();

        $response = $this->submitSetup($html, '000000');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('This code is not valid.', (string) $response->getContent());
        self::assertSame($this->shownSecret($html), $this->shownSecret((string) $response->getContent()));
        $this->entityManager()->clear();
        self::assertFalse($this->entityManager()->find(TotpAccount::class, $account->getId())?->isTotpAuthenticationEnabled());
        self::assertSame([], $this->enabledEvents);
    }

    public function testTheCodeIsAcceptedWithTheSpacesOfTheApp(): void
    {
        $this->account();
        $this->signIn();
        $html = (string) $this->send('GET', '/second-factor/setup')->getContent();
        $code = TOTP::createFromSecret($this->shownSecret($html))->now();

        self::assertSame(302, $this->submitSetup($html, substr($code, 0, 3).' '.substr($code, 3))->getStatusCode());
    }

    public function testTheWrongCodesAreLimited(): void
    {
        $this->account();
        $this->signIn();
        $html = (string) $this->send('GET', '/second-factor/setup')->getContent();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->submitSetup($html, '000000');
        }
        $response = $this->submitSetup($html, TOTP::createFromSecret($this->shownSecret($html))->now());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Too many wrong codes.', (string) $response->getContent());
    }

    public function testASubmissionWithoutTheCsrfTokenIsRefused(): void
    {
        $this->account();
        $this->signIn();
        $html = (string) $this->send('GET', '/second-factor/setup')->getContent();

        $response = $this->send('POST', '/second-factor/setup', ['_auth_code' => TOTP::createFromSecret($this->shownSecret($html))->now()]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnEnrolledAccountLeavesTheScreen(): void
    {
        $this->account('JBSWY3DPEHPK3PXP');
        $this->signIn();
        $html = (string) $this->send('GET', '/2fa')->getContent();
        $this->send('POST', '/2fa_check', ['_auth_code' => TOTP::createFromSecret('JBSWY3DPEHPK3PXP')->now(), '_csrf_token' => $this->csrfToken($html)]);

        $response = $this->send('GET', '/second-factor/setup');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
    }

    public function testTheScreenIsClosedToAVisitor(): void
    {
        self::assertSame(401, $this->send('GET', '/second-factor/setup')->getStatusCode());
    }

    public function testAnAccountThatDidNotEnrollCanStillLogOut(): void
    {
        $this->account();
        $this->signIn();

        $this->send('GET', '/logout');

        self::assertSame(401, $this->visitPrivatePage()->getStatusCode());
    }

    public function testEachFirewallHasItsEnrollmentScreenAndItsHome(): void
    {
        $this->account();
        $this->send('POST', '/admin/login', json: ['username' => 'alice@example.com', 'password' => 'secret']);

        $redirection = $this->send('GET', '/admin/private');
        self::assertSame('/admin/second-factor/setup', $redirection->headers->get('Location'));

        $html = (string) $this->send('GET', '/admin/second-factor/setup')->getContent();
        self::assertStringContainsString('<main data-layout="admin">', $html);
        $response = $this->send('POST', '/admin/second-factor/setup', ['_auth_code' => TOTP::createFromSecret($this->shownSecret($html))->now(), '_csrf_token' => $this->csrfToken($html)]);

        self::assertSame('/admin', $response->headers->get('Location'));
        self::assertSame('/admin/private', $this->send('GET', '/admin/private')->getContent());
    }

    private function account(?string $secret = null): TotpAccount
    {
        $account = new TotpAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $account->setTotpSecret($secret);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function submitSetup(string $html, string $code): Response
    {
        return $this->send('POST', '/second-factor/setup', ['_auth_code' => $code, '_csrf_token' => $this->csrfToken($html)]);
    }
}
