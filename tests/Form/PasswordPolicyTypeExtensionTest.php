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

namespace IQ2i\HerseBundle\Tests\Form;

use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use IQ2i\HerseBundle\Tests\PasswordPolicyKernelTestCase;
use IQ2i\HerseBundle\Tests\TestApplication\User;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

final class PasswordPolicyTypeExtensionTest extends PasswordPolicyKernelTestCase
{
    private const NEW_PASSWORD = ['attr' => ['autocomplete' => 'new-password']];
    private const HELP = 'Au moins 12 caractères, une lettre minuscule, une lettre majuscule, un chiffre, un caractère spécial.';

    protected function setUp(): void
    {
        if (!class_exists(Form::class)) {
            self::markTestSkipped('symfony/form is not installed.');
        }

        parent::setUp();
    }

    public function testAnUnmappedNewPasswordFieldIsChecked(): void
    {
        $form = $this->form()->add('password', PasswordType::class, self::NEW_PASSWORD + ['mapped' => false])->getForm();
        $form->submit(['password' => 'weak-password']);

        self::assertFalse($form->isValid());
        self::assertSame(['Le mot de passe doit contenir une lettre majuscule.', 'Le mot de passe doit contenir un chiffre.'], $this->errors($form->get('password')));
    }

    public function testACompliantPasswordIsAccepted(): void
    {
        $form = $this->form()->add('password', PasswordType::class, self::NEW_PASSWORD)->getForm();
        $form->submit(['password' => 'Abcdef1!ghij']);

        self::assertTrue($form->isValid());
    }

    public function testAnEmptyPasswordIsLeftToTheForm(): void
    {
        $form = $this->form()->add('password', PasswordType::class, self::NEW_PASSWORD + ['required' => false])->getForm();
        $form->submit(['password' => '']);

        self::assertTrue($form->isValid());
    }

    public function testTheLoginPasswordIsNotChecked(): void
    {
        $form = $this->form()->add('password', PasswordType::class, ['attr' => ['autocomplete' => 'current-password']])->getForm();
        $form->submit(['password' => 'weak']);

        self::assertTrue($form->isValid());
        self::assertNull($this->help($form->createView()['password']));
    }

    public function testThePolicyCanBeForcedOrSwitchedOffPerField(): void
    {
        $form = $this->form()
            ->add('forced', PasswordType::class, ['password_policy' => true])
            ->add('ignored', PasswordType::class, self::NEW_PASSWORD + ['password_policy' => false])
            ->getForm();
        $form->submit(['forced' => 'weak', 'ignored' => 'weak']);

        self::assertNotSame([], $this->errors($form->get('forced')));
        self::assertSame([], $this->errors($form->get('ignored')));
    }

    public function testOnlyTheFirstFieldOfARepeatedPasswordIsChecked(): void
    {
        $form = $this->form()->add('password', RepeatedType::class, ['type' => PasswordType::class, 'options' => self::NEW_PASSWORD, 'mapped' => false])->getForm();
        $form->submit(['password' => ['first' => 'weak-password', 'second' => 'weak-password']]);

        self::assertCount(2, $this->errors($form->get('password')->get('first')));
        self::assertSame([], $this->errors($form->get('password')->get('second')));
    }

    public function testThePolicyIsDescribedInTheHelpOfTheField(): void
    {
        $view = $this->form()
            ->add('password', RepeatedType::class, ['type' => PasswordType::class, 'options' => self::NEW_PASSWORD])
            ->add('custom', PasswordType::class, self::NEW_PASSWORD + ['help' => 'Choose wisely.'])
            ->getForm()
            ->createView();

        self::assertSame(self::HELP, $this->help($view['password']['first']));
        self::assertNull($this->help($view['password']['second']));
        self::assertSame('Choose wisely.', $this->help($view['custom']));
    }

    public function testThePlainPasswordOfADeclaredUserIsReportedOnce(): void
    {
        $form = $this->form(new User())->add('plainPassword', RepeatedType::class, ['type' => PasswordType::class, 'options' => self::NEW_PASSWORD])->getForm();
        $form->submit(['plainPassword' => ['first' => 'weak-password', 'second' => 'weak-password']]);

        self::assertFalse($form->isValid());
        self::assertSame(['Le mot de passe doit contenir une lettre majuscule.', 'Le mot de passe doit contenir un chiffre.'], $this->errors($form));
    }

    public function testThePasswordPolicyTypeReportsEachRuleOnce(): void
    {
        $form = $this->form()->add('password', PasswordPolicyType::class)->getForm();
        $form->submit(['password' => ['first' => 'weak-password', 'second' => 'weak-password']]);

        self::assertSame(['Le mot de passe doit contenir une lettre majuscule.', 'Le mot de passe doit contenir un chiffre.'], $this->errors($form));
    }

    private function help(FormView $view): mixed
    {
        /** @var array<string, mixed> $vars */
        $vars = $view->vars;

        return $vars['help'] ?? null;
    }

    private function form(?User $user = null): FormBuilderInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->kernel->getContainer()->get('test.form.factory');

        return $factory->createBuilder(FormType::class, $user, null === $user ? [] : ['data_class' => User::class]);
    }

    /**
     * @return list<string>
     */
    private function errors(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
