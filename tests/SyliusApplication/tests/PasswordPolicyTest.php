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

use App\Entity\User\AdminUser;
use App\Entity\User\ShopUser;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyViolationException;
use Sylius\Bundle\AdminBundle\Form\Model\PasswordReset as AdminPasswordReset;
use Sylius\Bundle\UserBundle\Form\Model\ChangePassword;
use Sylius\Bundle\UserBundle\Form\Model\PasswordReset;
use Sylius\Component\User\Security\PasswordUpdaterInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PasswordPolicyTest extends SyliusKernelTestCase
{
    public function testTheShopUserPlainPasswordFollowsThePolicyInTheRegistrationGroup(): void
    {
        $user = new ShopUser();
        $user->setPlainPassword('weak');

        $violations = $this->validate($user, ['sylius_user_registration']);

        self::assertGreaterThan(1, \count($violations));
        $this->assertOnlyOn($violations, 'plainPassword');

        $user->setPlainPassword('Abcdef1!ghij');
        self::assertCount(0, $this->validate($user, ['sylius_user_registration']));
    }

    public function testTheAdministratorPlainPasswordFollowsThePolicyInTheCreationGroup(): void
    {
        $admin = new AdminUser();
        $admin->setPlainPassword('weak');

        $violations = $this->validate($admin, ['sylius_user_create']);

        self::assertGreaterThan(1, \count($violations));
        $this->assertOnlyOn($violations, 'plainPassword');

        $admin->setPlainPassword('Abcdef1!ghij');
        self::assertCount(0, $this->validate($admin, ['sylius_user_create']));
    }

    public function testThePolicyIsNotAppliedOutsideTheDeclaredGroups(): void
    {
        $user = new ShopUser();
        $user->setPlainPassword('weak');

        self::assertCount(0, $this->validate($user, ['sylius_user_create']));
    }

    /**
     * Sylius validates these models, not the user, when a password is changed or reset: the mapping of the project covers them.
     */
    public function testTheModelsOfThePasswordChangeAndOfThePasswordResetFollowThePolicy(): void
    {
        $change = new ChangePassword();
        $change->setNewPassword('weak');
        $reset = new PasswordReset();
        $reset->setPassword('weak');
        $adminReset = new AdminPasswordReset();
        $adminReset->setPassword('weak');

        $this->assertOnlyOn($this->validateProperty($change, 'newPassword'), 'newPassword', true);
        $this->assertOnlyOn($this->validateProperty($reset, 'password'), 'password', true);
        $this->assertOnlyOn($this->validateProperty($adminReset, 'password'), 'password', true);

        $change->setNewPassword('Abcdef1!ghij');
        $reset->setPassword('Abcdef1!ghij');
        $adminReset->setPassword('Abcdef1!ghij');

        self::assertCount(0, $this->validateProperty($change, 'newPassword'));
        self::assertCount(0, $this->validateProperty($reset, 'password'));
        self::assertCount(0, $this->validateProperty($adminReset, 'password'));
    }

    /**
     * Sylius hashes through its own updater, whatever the screen or the command: a weak password never reaches the database.
     */
    public function testTheSyliusPasswordUpdaterRefusesAWeakPassword(): void
    {
        $updater = $this->service(PasswordUpdaterInterface::class, 'sylius.security.password_updater');

        $user = new ShopUser();
        $user->setPlainPassword(self::PASSWORD);
        $updater->updatePassword($user);
        self::assertNotNull($user->getPassword());

        $user->setPlainPassword('weak');
        $this->expectException(PasswordPolicyViolationException::class);
        $updater->updatePassword($user);
    }

    /**
     * @param list<string> $groups
     */
    private function validate(object $object, array $groups): ConstraintViolationListInterface
    {
        return $this->service(ValidatorInterface::class, 'validator')->validate($object, null, $groups);
    }

    /**
     * Only the property of the password: "ChangePassword" also checks the current password against the logged in user.
     */
    private function validateProperty(object $object, string $property): ConstraintViolationListInterface
    {
        return $this->service(ValidatorInterface::class, 'validator')->validateProperty($object, $property, ['sylius']);
    }

    /**
     * @param bool $strict whether the whole policy is expected, not only the rules Sylius already has
     */
    private function assertOnlyOn(ConstraintViolationListInterface $violations, string $property, bool $strict = false): void
    {
        self::assertGreaterThan($strict ? 1 : 0, \count($violations));
        foreach ($violations as $violation) {
            self::assertSame($property, $violation->getPropertyPath());
        }
    }
}
