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

namespace IQ2i\HerseBundle\Bridge\Moka\Form;

use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use Moka\Cms\Form\Type\ResetPasswordType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Applies the password policy to the only form of Moka that sets a password.
 *
 * The form has no data class and the user has no plain password property, so the policy cannot be mapped on a
 * class: the field is declared again with the type of the bundle.
 *
 * @internal
 */
final class ResetPasswordTypeExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [ResetPasswordType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordPolicyType::class, [
            'first_options' => [
                'label' => 'security.reset_password.password',
                'translation_domain' => 'moka',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'security.reset_password.password_confirm',
                'translation_domain' => 'moka',
                'attr' => ['autocomplete' => 'new-password'],
            ],
        ]);
    }
}
