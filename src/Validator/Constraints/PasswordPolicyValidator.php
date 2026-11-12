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

namespace IQ2i\HerseBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PasswordPolicyValidator extends ConstraintValidator
{
    private const CHARACTER_TYPES = [
        'Lowercase' => ['pattern' => '/\p{Ll}/u', 'code' => PasswordPolicy::MISSING_LOWERCASE_ERROR],
        'Uppercase' => ['pattern' => '/\p{Lu}/u', 'code' => PasswordPolicy::MISSING_UPPERCASE_ERROR],
        'Digit' => ['pattern' => '/\p{N}/u', 'code' => PasswordPolicy::MISSING_DIGIT_ERROR],
        'Special' => ['pattern' => '/[^\p{L}\p{N}]/u', 'code' => PasswordPolicy::MISSING_SPECIAL_CHARACTER_ERROR],
    ];

    public function __construct(
        private readonly int $minLength,
        private readonly int $maxLength,
        private readonly int $requiredCharacterTypes,
        private readonly bool $checkCompromised,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PasswordPolicy) {
            throw new UnexpectedTypeException($constraint, PasswordPolicy::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) && !$value instanceof \Stringable) {
            throw new UnexpectedValueException($value, 'string');
        }

        $password = (string) $value;

        if (!mb_check_encoding($password, 'UTF-8')) {
            $this->addViolation($constraint->invalidCharactersMessage, PasswordPolicy::INVALID_CHARACTERS_ERROR);

            return;
        }

        $length = mb_strlen($password, 'UTF-8');
        if ($length < $this->minLength) {
            $this->addViolation($constraint->tooShortMessage, PasswordPolicy::TOO_SHORT_ERROR, ['{{ limit }}' => (string) $this->minLength]);
        } elseif ($length > $this->maxLength) {
            $this->addViolation($constraint->tooLongMessage, PasswordPolicy::TOO_LONG_ERROR, ['{{ limit }}' => (string) $this->maxLength]);
        }

        $this->validateCharacterTypes($password, $constraint);

        if ($this->checkCompromised) {
            $this->context->getValidator()->inContext($this->context)->validate($password, new NotCompromisedPassword());
        }
    }

    private function validateCharacterTypes(string $password, PasswordPolicy $constraint): void
    {
        if ($this->requiredCharacterTypes <= 0) {
            return;
        }

        $missing = array_filter(
            self::CHARACTER_TYPES,
            static fn (array $type): bool => 1 !== preg_match($type['pattern'], $password),
        );

        if (\count(self::CHARACTER_TYPES) - \count($missing) >= $this->requiredCharacterTypes) {
            return;
        }

        // Every type is required: tell the user exactly which ones are missing.
        if (\count(self::CHARACTER_TYPES) === $this->requiredCharacterTypes) {
            foreach ($missing as $name => $type) {
                $this->addViolation($this->missingMessage($name, $constraint), $type['code']);
            }

            return;
        }

        $this->addViolation(
            $constraint->notEnoughCharacterTypesMessage,
            PasswordPolicy::NOT_ENOUGH_CHARACTER_TYPES_ERROR,
            ['{{ required }}' => (string) $this->requiredCharacterTypes],
        );
    }

    private function missingMessage(string $type, PasswordPolicy $constraint): string
    {
        return match ($type) {
            'Lowercase' => $constraint->missingLowercaseMessage,
            'Uppercase' => $constraint->missingUppercaseMessage,
            'Digit' => $constraint->missingDigitMessage,
            default => $constraint->missingSpecialCharacterMessage,
        };
    }

    /**
     * @param array<string, string> $parameters
     */
    private function addViolation(string $message, string $code, array $parameters = []): void
    {
        // The submitted password must never travel inside a violation.
        $this->context->buildViolation($message)
            ->setParameters($parameters)
            ->setInvalidValue(null)
            ->setCode($code)
            ->addViolation();
    }
}
