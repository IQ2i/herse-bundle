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

namespace IQ2i\HerseBundle\Tests\TestApplication;

use Symfony\Component\HttpFoundation\Response;

/**
 * Never reached: the firewall answers the login requests before the controller runs.
 */
final class LoginController
{
    public function __invoke(): Response
    {
        return new Response();
    }
}
