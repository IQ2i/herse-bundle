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

namespace IQ2i\HerseBundle\Tests;

use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class PasswordPolicyKernelTestCase extends HerseKernelTestCase
{
    protected static function environment(): string
    {
        return 'password_policy';
    }

    protected function validator(): ValidatorInterface
    {
        /** @var ValidatorInterface $validator */
        $validator = $this->kernel->getContainer()->get('test.validator');

        return $validator;
    }
}
