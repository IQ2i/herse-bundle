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

use Moka\Cms\Form\Type\SwitchType;
use Moka\Cms\Form\Type\UserType;
use Moka\Cms\Security\RoleManager;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Lets the administrators switch the second factor of a user on, from the user screen of Moka.
 *
 * @internal
 */
final class UserTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly RoleManager $roleManager,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [UserType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('emailAuthEnabled', SwitchType::class, [
            'label' => 'Two-factor authentication by email',
            'translation_domain' => 'iq2i_herse',
            'required' => false,
            // As the fields of Moka: read only for who may see the users without editing them.
            'disabled' => !$this->roleManager->isGranted('user', RoleManager::EDIT),
        ]);
    }
}
