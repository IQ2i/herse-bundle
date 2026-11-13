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

namespace IQ2i\HerseBundle\Tests\Test;

use IQ2i\HerseBundle\Test\TwoFactorTestTrait;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use IQ2i\HerseBundle\Tests\TwoFactorKernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The trait is used as a project does: with the test browser of Symfony.
 */
final class TwoFactorTestTraitTest extends TwoFactorKernelTestCase
{
    use TwoFactorTestTrait;

    protected static function environment(): string
    {
        return 'two_factor_screens';
    }

    public function testTheCodeOfTheUserPassesTheSecondFactor(): void
    {
        $client = $this->browser();
        $client->jsonRequest('POST', '/login', ['username' => 'alice@example.com', 'password' => 'secret']);
        $this->entityManager()->clear();
        $code = $this->entityManager()->getRepository(EmailAccount::class)->findOneBy(['email' => 'alice@example.com'])?->getEmailAuthCode();
        self::assertIsString($code);

        // The login leads to a page that redirects to the form: the helper follows it.
        $client->request('GET', '/private');
        $this->passSecondFactor($client, $code);

        $client->request('GET', '/private');
        self::assertSame('/private', $client->getResponse()->getContent());
    }

    public function testTheBrowserMustBeOnTheSecondFactorForm(): void
    {
        $client = $this->browser();
        $client->jsonRequest('POST', '/login', ['username' => 'alice@example.com', 'password' => 'wrong']);

        $this->expectExceptionMessage('The browser is not on the second factor form, but on "http://localhost/login".');

        $this->passSecondFactor($client, '123456');
    }

    public function testTheCodeOfAnAuthenticatorAppFollowsTheParametersOfTheBundle(): void
    {
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->totpCode('JBSWY3DPEHPK3PXP'));
    }

    /**
     * A browser on the kernel of the test, whose in-memory database must outlive the requests.
     */
    private function browser(): KernelBrowser
    {
        $this->entityManager()->persist(new EmailAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5])));
        $this->entityManager()->flush();

        $client = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();
        $client->catchExceptions(true);

        return $client;
    }
}
