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

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * The password policy configured under iq2i_herse.password_policy.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class PasswordPolicy extends Constraint
{
    public const VALIDATOR_SERVICE = 'iq2i_herse.password_policy.validator';

    public const TOO_SHORT_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c01';
    public const TOO_LONG_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c02';
    public const MISSING_LOWERCASE_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c03';
    public const MISSING_UPPERCASE_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c04';
    public const MISSING_DIGIT_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c05';
    public const MISSING_SPECIAL_CHARACTER_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c06';
    public const NOT_ENOUGH_CHARACTER_TYPES_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c07';
    public const INVALID_CHARACTERS_ERROR = '5b1f0a43-87c1-4f0d-9a52-2f4b6d3a1c08';

    protected const ERROR_NAMES = [
        self::TOO_SHORT_ERROR => 'TOO_SHORT_ERROR',
        self::TOO_LONG_ERROR => 'TOO_LONG_ERROR',
        self::MISSING_LOWERCASE_ERROR => 'MISSING_LOWERCASE_ERROR',
        self::MISSING_UPPERCASE_ERROR => 'MISSING_UPPERCASE_ERROR',
        self::MISSING_DIGIT_ERROR => 'MISSING_DIGIT_ERROR',
        self::MISSING_SPECIAL_CHARACTER_ERROR => 'MISSING_SPECIAL_CHARACTER_ERROR',
        self::NOT_ENOUGH_CHARACTER_TYPES_ERROR => 'NOT_ENOUGH_CHARACTER_TYPES_ERROR',
        self::INVALID_CHARACTERS_ERROR => 'INVALID_CHARACTERS_ERROR',
    ];

    public string $tooShortMessage = 'The password must be at least {{ limit }} characters long.';
    public string $tooLongMessage = 'The password must be at most {{ limit }} characters long.';
    public string $missingLowercaseMessage = 'The password must contain a lowercase letter.';
    public string $missingUppercaseMessage = 'The password must contain an uppercase letter.';
    public string $missingDigitMessage = 'The password must contain a digit.';
    public string $missingSpecialCharacterMessage = 'The password must contain a special character.';
    public string $notEnoughCharacterTypesMessage = 'The password must contain at least {{ required }} of these character types: lowercase letter, uppercase letter, digit, special character.';
    public string $invalidCharactersMessage = 'The password contains invalid characters.';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }

    public function validatedBy(): string
    {
        return self::VALIDATOR_SERVICE;
    }
}
