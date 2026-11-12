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

use IQ2i\HerseBundle\Tests\TestApplication\User;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;

final class PasswordPolicyFunctionalTest extends PasswordPolicyKernelTestCase
{
    public function testTheDeclaredUserClassesAreProtectedWithoutAnyAnnotation(): void
    {
        $violations = $this->validator()->validate(new User(plainPassword: 'weak'));

        self::assertSame(
            [
                'Le mot de passe doit contenir au moins 12 caractères.',
                'Le mot de passe doit contenir une lettre majuscule.',
                'Le mot de passe doit contenir un chiffre.',
                'Le mot de passe doit contenir un caractère spécial.',
            ],
            array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations)),
        );
    }

    public function testAStrongPlainPasswordIsAccepted(): void
    {
        self::assertCount(0, $this->validator()->validate(new User(plainPassword: 'Abcdef1!ghij')));
    }

    public function testAnUnsetPlainPasswordIsAccepted(): void
    {
        self::assertCount(0, $this->validator()->validate(new User()));
    }

    public function testTheConstraintReadsTheConfigurationWithoutArgument(): void
    {
        $violations = $this->validator()->validate('Abcdef1!gh', new PasswordPolicy());

        self::assertCount(1, $violations);
        self::assertSame('Le mot de passe doit contenir au moins 12 caractères.', (string) $violations->get(0)->getMessage());
    }
}
