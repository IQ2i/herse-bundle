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

use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project wrote no route, no template and no entity method: the routes, the form and the trait are the ones of the bundle.
 */
final class TwoFactorScreensTest extends TwoFactorKernelTestCase
{
    protected static function environment(): string
    {
        return 'two_factor_screens';
    }

    public function testTheCodeIsSentToTheAddressDeclaredForTheUserClass(): void
    {
        $this->account();

        $this->passwordStep();

        self::assertCount(1, $this->mails());
        self::assertSame('alice@example.com', $this->mails()[0]->getTo()[0]->getAddress());
    }

    public function testTheFormOfTheBundleAsksTheCode(): void
    {
        $this->account();
        $this->passwordStep();

        $form = $this->send('GET', '/2fa');

        self::assertSame(200, $form->getStatusCode());
        $html = (string) $form->getContent();
        self::assertStringContainsString('<title>Two-factor authentication</title>', $html);
        self::assertStringContainsString('<form action="/2fa_check" method="post"', $html);
        self::assertStringContainsString('Enter the code we have just sent to your email address.', $html);
        self::assertStringContainsString('name="_auth_code"', $html);
        self::assertStringContainsString('href="/logout"', $html);
        self::assertStringNotContainsString('name="_trusted"', $html);
    }

    public function testTheCodeOpensTheSession(): void
    {
        $this->account();
        $this->passwordStep();

        $this->submit('/2fa', $this->emailedCode());

        $this->assertLoggedIn();
    }

    public function testACodeSubmittedWithoutTheCsrfTokenIsRefused(): void
    {
        $this->account();
        $this->passwordStep();

        $this->send('POST', '/2fa_check', ['_auth_code' => $this->emailedCode()]);

        self::assertSame(302, $this->visitPrivatePage()->getStatusCode());
    }

    public function testAWrongCodeIsReportedOnTheForm(): void
    {
        $this->account();
        $this->passwordStep();

        $this->submit('/2fa', '000000');

        self::assertStringContainsString('role="alert"', (string) $this->send('GET', '/2fa')->getContent());
        self::assertSame(302, $this->visitPrivatePage()->getStatusCode());
    }

    public function testTheFormIsClosedToAVisitorWhoDidNotLogIn(): void
    {
        self::assertNotSame(200, $this->send('GET', '/2fa')->getStatusCode());
    }

    public function testEachFirewallHasItsRoutesUnderItsPrefixAndItsLayout(): void
    {
        $this->account();
        $this->passwordStep('/admin');

        $form = $this->send('GET', '/admin/2fa');
        $html = (string) $form->getContent();
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('<main data-layout="admin">', $html);
        self::assertStringContainsString('<form action="/admin/2fa_check" method="post"', $html);
        self::assertStringContainsString('href="/admin/logout"', $html);

        $this->submit('/admin/2fa', $this->emailedCode());

        self::assertSame('/admin/private', $this->send('GET', '/admin/private')->getContent());
        // The session of the administration does not open the other firewall.
        self::assertSame(401, $this->visitPrivatePage()->getStatusCode());
    }

    private function account(): EmailAccount
    {
        $account = new EmailAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();
        $this->resetMails();

        return $account;
    }

    private function passwordStep(string $prefix = ''): Response
    {
        return $this->send('POST', $prefix.'/login', json: ['username' => 'alice@example.com', 'password' => 'secret']);
    }

    /**
     * Submits the form as a browser does: with the CSRF token it carries.
     */
    private function submit(string $formPath, string $code): Response
    {
        $token = $this->csrfToken((string) $this->send('GET', $formPath)->getContent());

        return $this->send('POST', $formPath.'_check', ['_auth_code' => $code, '_csrf_token' => $token]);
    }
}
