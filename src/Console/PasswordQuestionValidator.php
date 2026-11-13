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

namespace IQ2i\HerseBundle\Console;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker;

/**
 * Validator of a console question asking for a new password: $question->setValidator($passwordQuestionValidator).
 */
final class PasswordQuestionValidator
{
    public function __construct(private readonly PasswordPolicyChecker $checker)
    {
    }

    public function __invoke(#[\SensitiveParameter] mixed $password): string
    {
        // A question left unanswered gives null.
        $password = \is_string($password) ? $password : '';
        $messages = $this->checker->messages($password);
        if ([] !== $messages) {
            throw new \RuntimeException(implode("\n", $messages));
        }

        return $password;
    }
}
