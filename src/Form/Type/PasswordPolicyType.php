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

namespace IQ2i\HerseBundle\Form\Type;

use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A repeated password field that enforces the password policy.
 */
final class PasswordPolicyType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'type' => PasswordType::class,
            'constraints' => [new PasswordPolicy()],
            // The constraint above already reports the broken rules.
            'options' => ['password_policy' => false],
            'invalid_message' => 'The password fields must match.',
            'first_options' => ['attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['attr' => ['autocomplete' => 'new-password']],
        ]);
    }

    public function getParent(): string
    {
        return RepeatedType::class;
    }
}
