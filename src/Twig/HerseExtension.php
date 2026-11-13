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

namespace IQ2i\HerseBundle\Twig;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class HerseExtension extends AbstractExtension
{
    public function __construct(private readonly PasswordPolicyDescriber $passwordPolicy)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('herse_password_policy', $this->passwordPolicy(...)),
        ];
    }

    /**
     * @return list<string> the rules of the password policy, translated
     */
    public function passwordPolicy(): array
    {
        return $this->passwordPolicy->rules();
    }
}
