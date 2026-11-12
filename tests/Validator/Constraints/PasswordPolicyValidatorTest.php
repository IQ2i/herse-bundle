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

namespace IQ2i\HerseBundle\Tests\Validator\Constraints;

use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<PasswordPolicyValidator>
 */
final class PasswordPolicyValidatorTest extends ConstraintValidatorTestCase
{
    private const VALID = 'Abcdef1!ghij';

    protected function createValidator(): PasswordPolicyValidator
    {
        return new PasswordPolicyValidator(12, 20, 4, false);
    }

    public function testAValidPasswordIsAccepted(): void
    {
        $this->validator->validate(self::VALID, new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testNullAndEmptyStringAreIgnored(): void
    {
        $this->validator->validate(null, new PasswordPolicy());
        $this->validator->validate('', new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testAStringableIsAccepted(): void
    {
        $this->validator->validate(new class implements \Stringable {
            public function __toString(): string
            {
                return 'Abcdef1!ghij';
            }
        }, new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testAnotherConstraintIsRejected(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(self::VALID, new NotCompromisedPassword());
    }

    public function testANonStringValueIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(['password'], new PasswordPolicy());
    }

    public function testATooShortPasswordIsRejected(): void
    {
        $this->validator->validate('Abcdef1!gh', new PasswordPolicy());

        $this->buildViolation('The password must be at least {{ limit }} characters long.')
            ->setParameter('{{ limit }}', '12')
            ->setInvalidValue(null)
            ->setCode(PasswordPolicy::TOO_SHORT_ERROR)
            ->assertRaised();
    }

    public function testATooLongPasswordIsRejected(): void
    {
        $this->validator->validate('Abcdef1!ghijklmnopqrs', new PasswordPolicy());

        $this->buildViolation('The password must be at most {{ limit }} characters long.')
            ->setParameter('{{ limit }}', '20')
            ->setInvalidValue(null)
            ->setCode(PasswordPolicy::TOO_LONG_ERROR)
            ->assertRaised();
    }

    public function testTheLengthCountsCharactersNotBytes(): void
    {
        $this->validator->validate('Éééééé1!éééé', new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testAMissingLowercaseLetterIsRejected(): void
    {
        $this->validator->validate('ABCDEF1!GHIJ', new PasswordPolicy());

        $this->assertSingleViolation('The password must contain a lowercase letter.', PasswordPolicy::MISSING_LOWERCASE_ERROR);
    }

    public function testAMissingUppercaseLetterIsRejected(): void
    {
        $this->validator->validate('abcdef1!ghij', new PasswordPolicy());

        $this->assertSingleViolation('The password must contain an uppercase letter.', PasswordPolicy::MISSING_UPPERCASE_ERROR);
    }

    public function testAMissingDigitIsRejected(): void
    {
        $this->validator->validate('Abcdefg!ghij', new PasswordPolicy());

        $this->assertSingleViolation('The password must contain a digit.', PasswordPolicy::MISSING_DIGIT_ERROR);
    }

    public function testAMissingSpecialCharacterIsRejected(): void
    {
        $this->validator->validate('Abcdef1ghijk', new PasswordPolicy());

        $this->assertSingleViolation('The password must contain a special character.', PasswordPolicy::MISSING_SPECIAL_CHARACTER_ERROR);
    }

    public function testEveryMissingTypeGetsItsOwnViolation(): void
    {
        $this->validator->validate('aaaaaaaaaaaa', new PasswordPolicy());

        $this->buildViolation('The password must contain an uppercase letter.')
            ->setInvalidValue(null)
            ->setCode(PasswordPolicy::MISSING_UPPERCASE_ERROR)
            ->buildNextViolation('The password must contain a digit.')
            ->setInvalidValue(null)
            ->setCode(PasswordPolicy::MISSING_DIGIT_ERROR)
            ->buildNextViolation('The password must contain a special character.')
            ->setInvalidValue(null)
            ->setCode(PasswordPolicy::MISSING_SPECIAL_CHARACTER_ERROR)
            ->assertRaised();
    }

    public function testAccentedLettersCountAsLetters(): void
    {
        $this->validator->validate('éÉ1!éééééééé', new PasswordPolicy());

        $this->assertNoViolation();
    }

    #[DataProvider('provideSpecialCharacters')]
    public function testAnythingThatIsNotALetterNorADigitIsSpecial(string $special): void
    {
        $this->validator->validate('Abcdefg1hij'.$special, new PasswordPolicy());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSpecialCharacters(): iterable
    {
        yield 'punctuation' => ['!'];
        yield 'space' => [' '];
        yield 'emoji' => ['🔒'];
        yield 'currency' => ['€'];
    }

    public function testAnInvalidEncodingIsRejected(): void
    {
        $this->validator->validate("Abcdef1!\xff\xfeghij", new PasswordPolicy());

        $this->assertSingleViolation('The password contains invalid characters.', PasswordPolicy::INVALID_CHARACTERS_ERROR);
    }

    public function testFewerRequiredTypesGetASingleViolation(): void
    {
        $this->validator = new PasswordPolicyValidator(12, 20, 3, false);
        $this->validator->initialize($this->context);

        $this->validator->validate('abcdefghijkl1', new PasswordPolicy());

        $this->buildViolation('The password must contain at least {{ required }} of these character types: lowercase letter, uppercase letter, digit, special character.')
            ->setParameter('{{ required }}', '3')
            ->setInvalidValue(null)
            ->setCode(PasswordPolicy::NOT_ENOUGH_CHARACTER_TYPES_ERROR)
            ->assertRaised();
    }

    public function testFewerRequiredTypesAreAcceptedWhenReached(): void
    {
        $this->validator = new PasswordPolicyValidator(12, 20, 3, false);
        $this->validator->initialize($this->context);

        $this->validator->validate('abcdefghijkL1', new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testRequiringNoTypeDisablesTheRule(): void
    {
        $this->validator = new PasswordPolicyValidator(12, 20, 0, false);
        $this->validator->initialize($this->context);

        $this->validator->validate('aaaaaaaaaaaa', new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testTheCompromisedPasswordCheckIsDelegatedToTheNativeConstraint(): void
    {
        $this->validator = new PasswordPolicyValidator(12, 20, 4, true);
        $this->validator->initialize($this->context);

        $this->expectValidateValue(0, self::VALID, [new NotCompromisedPassword()]);

        $this->validator->validate(self::VALID, new PasswordPolicy());
    }

    public function testTheCompromisedPasswordCheckIsSkippedByDefault(): void
    {
        $this->expectNoValidate();

        $this->validator->validate(self::VALID, new PasswordPolicy());

        $this->assertNoViolation();
    }

    private function assertSingleViolation(string $message, string $code): void
    {
        $this->buildViolation($message)->setInvalidValue(null)->setCode($code)->assertRaised();
    }
}
