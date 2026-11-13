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

use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Checks a plain password against the policy outside of a validated object: a console command, an import, a service.
 */
final class PasswordPolicyChecker
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @param bool $checkCompromised false to skip the data breach lookup, a network call, when the policy enables it
     *
     * @return ConstraintViolationListInterface the broken rules, with translated messages; an empty password breaks the policy
     */
    public function violations(#[\SensitiveParameter] string $password, bool $checkCompromised = true): ConstraintViolationListInterface
    {
        return $this->validator->validate($password, [new NotBlank(), new PasswordPolicy(checkCompromised: $checkCompromised)]);
    }

    /**
     * @return list<string> the translated messages of the broken rules
     */
    public function messages(#[\SensitiveParameter] string $password, bool $checkCompromised = true): array
    {
        $messages = [];
        foreach ($this->violations($password, $checkCompromised) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
