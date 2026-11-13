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

/**
 * A password that breaks the policy was about to be hashed. It never carries the password.
 */
final class PasswordPolicyViolationException extends \RuntimeException
{
    /**
     * @param list<string> $messages the translated messages of the broken rules
     */
    public function __construct(private readonly array $messages)
    {
        parent::__construct('The password does not comply with the password policy: '.implode(' ', $messages));
    }

    /**
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
