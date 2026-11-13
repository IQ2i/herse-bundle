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

namespace IQ2i\HerseBundle\PasswordPolicy;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Describes the configured policy to the person choosing a password, e.g. as the help of a form field.
 */
final class PasswordPolicyDescriber
{
    private const CHARACTER_TYPES = ['a lowercase letter', 'an uppercase letter', 'a digit', 'a special character'];

    public function __construct(
        private readonly int $minLength,
        private readonly int $requiredCharacterTypes,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * @return list<string> one entry per rule, e.g. "at least 12 characters"
     */
    public function rules(?string $locale = null): array
    {
        $rules = [$this->translate('at least %count% characters', ['%count%' => $this->minLength], $locale)];
        $types = array_map(fn (string $type): string => $this->translate($type, [], $locale), self::CHARACTER_TYPES);

        if (\count($types) === $this->requiredCharacterTypes) {
            return [...$rules, ...$types];
        }

        if ($this->requiredCharacterTypes > 0) {
            $rules[] = $this->translate('at least %count% of these: %types%', ['%count%' => $this->requiredCharacterTypes, '%types%' => implode(', ', $types)], $locale);
        }

        return $rules;
    }

    /**
     * @return string the rules in one sentence, e.g. "At least 12 characters, a lowercase letter, a digit."
     */
    public function summary(?string $locale = null): string
    {
        $summary = implode(', ', $this->rules($locale));

        return mb_strtoupper(mb_substr($summary, 0, 1)).mb_substr($summary, 1).'.';
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function translate(string $message, array $parameters, ?string $locale): string
    {
        return $this->translator?->trans($message, $parameters, 'iq2i_herse', $locale) ?? strtr($message, array_map(strval(...), $parameters));
    }
}
