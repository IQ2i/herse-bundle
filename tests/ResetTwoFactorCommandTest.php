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

use IQ2i\HerseBundle\Command\ResetTwoFactorCommand;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use OTPHP\TOTP;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Two user classes are declared: the accounts using an authenticator app ("customer") and the others ("member").
 */
final class ResetTwoFactorCommandTest extends TwoFactorKernelTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected static function environment(): string
    {
        return 'two_factor_screens_totp_devices';
    }

    public function testTheSecondFactorOfTheUserIsRemovedWithItsTrustedDevices(): void
    {
        $account = $this->account();
        $this->signIn();
        $html = (string) $this->send('GET', '/2fa')->getContent();
        $this->send('POST', '/2fa_check', ['_auth_code' => TOTP::createFromSecret(self::SECRET)->now(), '_csrf_token' => $this->csrfToken($html), '_trusted' => '1']);
        self::assertCount(1, $this->devices());

        $tester = $this->execute(['email' => 'alice@example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('The second factor of "alice@example.com" (customer) was reset.', $tester->getDisplay());
        self::assertSame([], $this->devices());
        self::assertFalse($this->entityManager()->find(TotpAccount::class, $account->getId())?->isTotpAuthenticationEnabled());
    }

    public function testAnUnknownAddressIsReported(): void
    {
        $tester = $this->execute(['email' => 'nobody@example.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No user has the email address "nobody@example.com".', $tester->getDisplay());
    }

    public function testTheTypeIsAskedWhenSeveralKindsOfUsersShareTheAddress(): void
    {
        $account = $this->account();
        $this->entityManager()->persist(new EmailAccount('alice@example.com'));
        $this->entityManager()->flush();

        $tester = $this->execute(['email' => 'alice@example.com']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('choose one with --type (customer, member)', $tester->getDisplay());

        $tester = $this->execute(['email' => 'alice@example.com', '--type' => 'member']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not implement', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        self::assertSame(Command::SUCCESS, $this->execute(['email' => 'alice@example.com', '--type' => 'customer'])->getStatusCode());
        $this->entityManager()->clear();
        self::assertFalse($this->entityManager()->find(TotpAccount::class, $account->getId())?->isTotpAuthenticationEnabled());
    }

    private function account(): TotpAccount
    {
        $account = new TotpAccount('alice@example.com', password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]));
        $account->setTotpSecret(self::SECRET);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    /**
     * @param array<string, string> $input
     */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester($this->service(ResetTwoFactorCommand::class, ResetTwoFactorCommand::class));
        $tester->execute($input);

        return $tester;
    }
}
