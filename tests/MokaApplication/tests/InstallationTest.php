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

namespace App\Tests;

use App\Kernel;
use IQ2i\HerseBundle\User\UserResolver;
use Moka\Cms\Entity\User\AbstractUser;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The user class of Moka is generated in the cache: the bridge declares its parent class.
 */
final class InstallationTest extends MokaKernelTestCase
{
    public function testTheContainerCompilesWhenTheUserClassIsNotGeneratedYet(): void
    {
        $cacheDir = $this->kernel->getCacheDir();
        $this->kernel->shutdown();
        (new Filesystem())->remove($cacheDir);

        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();

        self::assertSame([AbstractUser::class], array_keys((array) $this->kernel->getContainer()->getParameter('iq2i_herse.users')));
        self::assertFileExists($cacheDir.'/moka/entity/User/User.php');
    }

    public function testTheGeneratedUserIsRecognisedThroughItsParentClass(): void
    {
        $admin = $this->createAdmin();

        self::assertSame(self::USER_CLASS, $admin::class);
        $reference = $this->service(UserResolver::class, 'iq2i_herse.user_resolver')->reference($admin);
        self::assertSame('admin', $reference?->type);
        self::assertSame((string) $admin->getId(), $reference->identifier);
    }

    public function testTheSenderOfMokaIsTheOneOfTheSecurityEmails(): void
    {
        $this->createAdmin();
        $this->login();

        self::assertSame('robot@example.com', $this->mails()[0]->getFrom()[0]->getAddress());
    }

    public function testTheUserOfMokaIsLeftAsItIsWithoutTheSecondFactor(): void
    {
        self::assertNotInstanceOf(TwoFactorInterface::class, $this->createAdmin());
    }
}
