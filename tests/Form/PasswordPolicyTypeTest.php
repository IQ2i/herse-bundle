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
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class PasswordPolicyTypeTest extends PasswordPolicyKernelTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Form::class)) {
            self::markTestSkipped('symfony/form is not installed.');
        }

        parent::setUp();
    }

    public function testDifferentPasswordsAreRejected(): void
    {
        $form = $this->submit('Abcdef1!ghij', 'Abcdef1!ghik');

        self::assertFalse($form->isValid());
        self::assertSame('Les mots de passe doivent être identiques.', $form->get('password')->getErrors(true)[0]->getMessage());
    }

    public function testAWeakPasswordIsRejected(): void
    {
        $form = $this->submit('weak', 'weak');

        self::assertFalse($form->isValid());
        self::assertSame('Le mot de passe doit contenir au moins 12 caractères.', $form->get('password')->getErrors(true)[0]->getMessage());
    }

    public function testAStrongPasswordIsAccepted(): void
    {
        $form = $this->submit('Abcdef1!ghij', 'Abcdef1!ghij');

        self::assertTrue($form->isValid());
        self::assertSame('Abcdef1!ghij', $form->get('password')->getData());
    }

    public function testTheBrowserIsToldItIsANewPassword(): void
    {
        $view = $this->submit('', '')->createView();

        foreach (['first', 'second'] as $field) {
            self::assertSame(['autocomplete' => 'new-password'], $view['password'][$field]->vars['attr'] ?? null);
        }
    }

    private function submit(string $first, string $second): FormInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->kernel->getContainer()->get('test.form.factory');
        $form = $factory->createBuilder(FormType::class)->add('password', PasswordPolicyType::class)->getForm();
        $form->submit(['password' => ['first' => $first, 'second' => $second]]);

        return $form;
    }
}
