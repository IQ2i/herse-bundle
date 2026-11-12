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

final class PasswordChangeUnmappedFieldTest extends PasswordChangeKernelTestCase
{
    protected static function environment(): string
    {
        return 'password_change_notification_unmapped';
    }

    public function testAPasswordFieldNotMappedByDoctrineIsReported(): void
    {
        $account = $this->createAccount();

        $this->expectExceptionMessage('"transient" is not a field mapped by Doctrine');

        $this->changePassword($account);
    }
}
