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

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use IQ2i\HerseBundle\Tests\PasswordPolicyKernelTestCase;

final class PasswordPolicyDescriberTest extends PasswordPolicyKernelTestCase
{
    public function testEveryRuleIsListedWhenEveryCharacterTypeIsRequired(): void
    {
        $describer = new PasswordPolicyDescriber(12, 4);

        self::assertSame(['at least 12 characters', 'a lowercase letter', 'an uppercase letter', 'a digit', 'a special character'], $describer->rules());
        self::assertSame('At least 12 characters, a lowercase letter, an uppercase letter, a digit, a special character.', $describer->summary());
    }

    public function testTheCharacterTypesAreGroupedWhenOnlySomeAreRequired(): void
    {
        self::assertSame(
            ['at least 8 characters', 'at least 2 of these: a lowercase letter, an uppercase letter, a digit, a special character'],
            (new PasswordPolicyDescriber(8, 2))->rules(),
        );
    }

    public function testTheCharacterTypesAreLeftOutWhenNoneIsRequired(): void
    {
        self::assertSame('At least 16 characters.', (new PasswordPolicyDescriber(16, 0))->summary());
    }

    public function testTheConfiguredPolicyIsDescribedInTheLocaleOfTheApplication(): void
    {
        /** @var PasswordPolicyDescriber $describer */
        $describer = $this->kernel->getContainer()->get(PasswordPolicyDescriber::class);

        self::assertSame('Au moins 12 caractères, une lettre minuscule, une lettre majuscule, un chiffre, un caractère spécial.', $describer->summary());
    }
}
