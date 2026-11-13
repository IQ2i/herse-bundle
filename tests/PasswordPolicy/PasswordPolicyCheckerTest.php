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

namespace IQ2i\HerseBundle\Tests\PasswordPolicy;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker;
use IQ2i\HerseBundle\Tests\PasswordPolicyKernelTestCase;

final class PasswordPolicyCheckerTest extends PasswordPolicyKernelTestCase
{
    public function testACompliantPasswordBreaksNoRule(): void
    {
        self::assertCount(0, $this->checker()->violations('Abcdef1!ghij'));
        self::assertSame([], $this->checker()->messages('Abcdef1!ghij'));
    }

    public function testEachBrokenRuleIsReportedWithATranslatedMessage(): void
    {
        self::assertSame(
            ['Le mot de passe doit contenir au moins 12 caractères.', 'Le mot de passe doit contenir une lettre majuscule.', 'Le mot de passe doit contenir un chiffre.', 'Le mot de passe doit contenir un caractère spécial.'],
            $this->checker()->messages('weak'),
        );
    }

    public function testAnEmptyPasswordBreaksThePolicy(): void
    {
        self::assertCount(1, $this->checker()->violations(''));
    }

    public function testTheViolationsNeverCarryThePassword(): void
    {
        foreach ($this->checker()->violations('weak') as $violation) {
            self::assertNull($violation->getInvalidValue());
        }
    }

    private function checker(): PasswordPolicyChecker
    {
        /** @var PasswordPolicyChecker $checker */
        $checker = $this->kernel->getContainer()->get(PasswordPolicyChecker::class);

        return $checker;
    }
}
