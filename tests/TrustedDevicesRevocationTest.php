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

use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class TrustedDevicesRevocationTest extends TrustedDevicesKernelTestCase
{
    protected static function environment(): string
    {
        return 'trusted_devices_revoke';
    }

    public function testTheDevicesAreRevokedWhenAnAdministratorChangesThePassword(): void
    {
        $account = $this->account();
        $this->signIn();
        $this->signIn(userAgent: self::CHROME);
        $admin = $this->createAccount('admin@example.com');
        $this->webRequest($admin);

        $this->changePassword($account);

        self::assertSame([], $this->devices());
        self::assertCount(1, $this->mails(), 'The owner is still warned.');
    }

    public function testTheDevicesAreRevokedWhenThePasswordChangesFromTheConsole(): void
    {
        $account = $this->account();
        $this->signIn();

        $this->changePassword($account);

        self::assertSame([], $this->devices());
    }

    public function testTheOwnerKeepsTheBrowserTheyAreUsing(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $this->signIn(userAgent: self::CHROME);
        $this->webRequest($account, ['herse_device_main' => $token]);

        $this->changePassword($account);

        self::assertSame([hash('sha256', $token)], array_map(static fn ($device): string => $device->getTokenHash(), $this->devices()));
    }

    public function testAResetByLinkRevokesTheDevicesOfAnonymousVisitors(): void
    {
        $account = $this->account();
        $this->signIn();
        $this->webRequest(null, ['herse_device_main' => str_repeat('b', 64)]);

        $this->changePassword($account);

        self::assertSame([], $this->devices());
    }

    public function testTheAutomaticRehashDoesNotRevokeTheDevices(): void
    {
        $account = $this->account();
        $token = $this->token($this->signIn());
        $stale = password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 4]);
        $this->entityManager()->getConnection()->executeStatement('UPDATE account SET password = ?', [$stale]);

        $this->signIn(cookies: ['herse_device_main' => $token]);

        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(Account::class, $account->getId());
        self::assertNotNull($reloaded);
        self::assertNotSame($stale, $reloaded->getPassword(), 'The login must have rehashed the password, or this test proves nothing.');
        self::assertCount(1, $this->devices());
    }

    public function testAFailureToRevokeNeverBlocksTheNotification(): void
    {
        $account = $this->account();
        $this->entityManager()->getConnection()->executeStatement('DROP TABLE iq2i_herse_trusted_device');

        $this->changePassword($account);

        self::assertCount(1, $this->mails());
        self::assertSame(['The trusted devices could not be revoked after the password change.'], $this->logger()->messages('error'));
    }

    /**
     * The kernel resets the entity manager between two requests: the account is loaded again.
     */
    private function changePassword(Account $account): void
    {
        $managed = $this->entityManager()->find(Account::class, $account->getId());
        self::assertNotNull($managed);
        $managed->setPassword('new-hash');
        $this->entityManager()->flush();
    }

    /**
     * @param array<string, string> $cookies
     */
    private function webRequest(?Account $authenticated, array $cookies = []): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $requests = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/', 'GET', [], $cookies));

        if (null !== $authenticated) {
            $tokens = $container->get('security.token_storage');
            self::assertInstanceOf(TokenStorageInterface::class, $tokens);
            $tokens->setToken(new UsernamePasswordToken($authenticated, 'main', $authenticated->getRoles()));
        }
    }
}
