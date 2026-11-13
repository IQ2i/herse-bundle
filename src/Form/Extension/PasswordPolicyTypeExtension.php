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

namespace IQ2i\HerseBundle\Form\Extension;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Applies the password policy to the fields where a password is chosen, mapped to an object or not.
 *
 * The check runs on the submitted value rather than through a constraint, so it depends neither on the validation
 * groups of the form nor on a property of the model.
 */
final class PasswordPolicyTypeExtension extends AbstractTypeExtension
{
    /**
     * @param array<class-string, array{property: string, groups: list<string>}> $mapping the properties the policy is already mapped on
     */
    public function __construct(
        private readonly PasswordPolicyChecker $checker,
        private readonly PasswordPolicyDescriber $describer,
        private readonly array $mapping,
        private readonly bool $auto,
        private readonly bool $help,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [PasswordType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('password_policy', null);
        $resolver->setAllowedTypes('password_policy', ['null', 'bool']);
        $resolver->setInfo('password_policy', 'Whether the password policy of Herse applies. By default, it does on a field with autocomplete="new-password".');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$this->applies($options)) {
            return;
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $password = $event->getData();
            if (!\is_string($password) || '' === $password || $this->isHandledElsewhere($form)) {
                return;
            }

            foreach ($this->checker->messages($password) as $message) {
                $form->addError(new FormError($message));
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        if ($this->help && null === ($view->vars['help'] ?? null) && $this->applies($options) && !$this->isConfirmation($form)) {
            $view->vars['help'] = $this->describer->summary();
            $view->vars['help_translation_parameters'] = [];
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applies(array $options): bool
    {
        if (\is_bool($options['password_policy'])) {
            return $options['password_policy'];
        }

        $attributes = \is_array($options['attr'] ?? null) ? $options['attr'] : [];

        return $this->auto && 'new-password' === ($attributes['autocomplete'] ?? null);
    }

    private function isHandledElsewhere(FormInterface $form): bool
    {
        if ($this->isConfirmation($form)) {
            return true;
        }

        // The constraint mapped on the plain password of the model reports the same rules.
        $field = $this->repeatedParent($form) ?? $form;
        $class = $field->getParent()?->getConfig()->getDataClass();
        if (null === $class || !$field->getConfig()->getMapped()) {
            return false;
        }

        foreach ($this->mapping as $mappedClass => $mapped) {
            if (is_a($class, $mappedClass, true) && $mapped['property'] === (string) $field->getPropertyPath()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The second field of a repeated password only has to match the first one.
     */
    private function isConfirmation(FormInterface $form): bool
    {
        return $form->getName() === $this->repeatedParent($form)?->getConfig()->getOption('second_name');
    }

    private function repeatedParent(FormInterface $form): ?FormInterface
    {
        $parent = $form->getParent();

        return $parent?->getConfig()->getType()->getInnerType() instanceof RepeatedType ? $parent : null;
    }
}
