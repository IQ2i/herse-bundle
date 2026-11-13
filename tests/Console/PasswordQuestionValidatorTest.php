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

namespace IQ2i\HerseBundle\Tests\Console;

use IQ2i\HerseBundle\Console\PasswordQuestionValidator;
use IQ2i\HerseBundle\Tests\PasswordPolicyKernelTestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;

final class PasswordQuestionValidatorTest extends PasswordPolicyKernelTestCase
{
    public function testACompliantPasswordIsReturned(): void
    {
        self::assertSame('Abcdef1!ghij', ($this->questionValidator())('Abcdef1!ghij'));
    }

    public function testAWeakPasswordIsRefusedWithEveryBrokenRule(): void
    {
        $this->expectExceptionMessage("Le mot de passe doit contenir une lettre majuscule.\nLe mot de passe doit contenir un chiffre.");

        ($this->questionValidator())('weak-password');
    }

    public function testAMissingAnswerIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        ($this->questionValidator())(null);
    }

    public function testTheQuestionIsAskedAgainUntilThePasswordComplies(): void
    {
        $question = (new Question('Password: '))->setValidator($this->questionValidator())->setMaxAttempts(2);
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "weak\nAbcdef1!ghij\n");
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput();

        self::assertSame('Abcdef1!ghij', (new QuestionHelper())->ask($input, $output, $question));
        self::assertStringContainsString('Le mot de passe doit contenir au moins 12 caractères.', $output->fetch());
    }

    private function questionValidator(): PasswordQuestionValidator
    {
        /** @var PasswordQuestionValidator $validator */
        $validator = $this->kernel->getContainer()->get(PasswordQuestionValidator::class);

        return $validator;
    }
}
