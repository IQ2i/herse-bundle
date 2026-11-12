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

use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TwoFactorAccount;
use IQ2i\HerseBundle\Tests\TwoFactorKernelTestCase;
use IQ2i\HerseBundle\TrustedDevice\DeviceCookies;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceRepository;
use IQ2i\HerseBundle\TrustedDevice\TrustLevel;
use IQ2i\HerseBundle\TwoFactor\TrustedDeviceManager;
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class TrustedDeviceManagerTest extends TwoFactorKernelTestCase
{
    private const TOKEN = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    protected static function environment(): string
    {
        return 'two_factor_bridge';
    }

    public function testTheTrustCanOnlyBeSetOnAWatchedFirewallForADeclaredUser(): void
    {
        $account = $this->twoFactorAccount();
        $manager = $this->manager(null);

        self::assertTrue($manager->canSetTrustedDevice($account, Request::create('/'), 'main'));
        self::assertFalse($manager->canSetTrustedDevice($account, Request::create('/'), 'public'));
        self::assertFalse($manager->canSetTrustedDevice(new \stdClass(), Request::create('/'), 'main'));
    }

    public function testADeviceIsVerifiedThroughItsCookie(): void
    {
        $account = $this->twoFactorAccount();
        $this->device($account);
        $manager = $this->manager(self::TOKEN);

        self::assertFalse($manager->isTrustedDevice($account, 'main'), 'A known device is not a verified one.');

        $manager->addTrustedDevice($account, 'main');

        self::assertTrue($manager->isTrustedDevice($account, 'main'));
        self::assertSame(TrustLevel::Verified, $this->devices()[0]->getTrustLevel());
    }

    public function testNothingIsTrustedWithoutARequestOrACookie(): void
    {
        $account = $this->twoFactorAccount();
        $this->device($account);

        foreach ([null, 'not-a-token'] as $token) {
            $manager = $this->manager($token);
            $manager->addTrustedDevice($account, 'main');

            self::assertFalse($manager->isTrustedDevice($account, 'main'));
        }
        self::assertFalse((new TrustedDeviceManager($this->resolver(), new TrustedDeviceRepository($this->entityManager()), $this->cookies(), new RequestStack(), '30 days'))->isTrustedDevice($account, 'main'));
        self::assertSame(TrustLevel::Known, $this->devices()[0]->getTrustLevel());
    }

    public function testNothingIsTrustedForAnUndeclaredUserOrAnUnknownDevice(): void
    {
        $account = $this->twoFactorAccount();
        $manager = $this->manager(self::TOKEN);

        $manager->addTrustedDevice(new \stdClass(), 'main');
        $manager->addTrustedDevice($account, 'main');

        self::assertFalse($manager->isTrustedDevice(new \stdClass(), 'main'));
        self::assertFalse($manager->isTrustedDevice($account, 'main'));
        self::assertSame([], $this->devices());
    }

    public function testAnExpiredDeviceIsNeverVerified(): void
    {
        $account = $this->twoFactorAccount();
        $this->device($account, new \DateTimeImmutable('-1 minute'));
        $manager = $this->manager(self::TOKEN);

        $manager->addTrustedDevice($account, 'main');

        self::assertFalse($manager->isTrustedDevice($account, 'main'));
        self::assertSame(TrustLevel::Known, $this->devices()[0]->getTrustLevel());
    }

    private function device(TwoFactorAccount $account, ?\DateTimeImmutable $expiresAt = null): void
    {
        $this->entityManager()->persist(new TrustedDevice('customer', (string) $account->getId(), 'main', hash('sha256', self::TOKEN), null, null, new \DateTimeImmutable('-1 day'), $expiresAt ?? new \DateTimeImmutable('+1 day')));
        $this->entityManager()->flush();
    }

    private function manager(?string $cookie): TrustedDeviceManager
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', cookies: null === $cookie ? [] : ['herse_device_main' => $cookie]));

        return new TrustedDeviceManager($this->resolver(), new TrustedDeviceRepository($this->entityManager()), $this->cookies(), $stack, '30 days');
    }

    private function cookies(): DeviceCookies
    {
        return new DeviceCookies(['main' => ['cookie_name' => 'herse_device_main', 'lifetime' => '30 days']]);
    }

    private function resolver(): UserResolver
    {
        return new UserResolver([TwoFactorAccount::class => ['type' => 'customer', 'email' => 'email', 'id' => 'id']], PropertyAccess::createPropertyAccessor());
    }
}
