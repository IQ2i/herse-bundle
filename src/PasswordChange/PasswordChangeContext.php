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

namespace IQ2i\HerseBundle\PasswordChange;

/**
 * Who changed the password.
 */
enum PasswordChangeContext
{
    /** From a web request, by the account owner or by someone holding a reset link (nobody is authenticated). */
    case Self;

    /** From a web request, by another authenticated user, typically an administrator, including one impersonating the owner. */
    case OtherUser;

    /** Outside of any web request: a console command, a worker. */
    case Console;
}
