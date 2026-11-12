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

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page that tells where it is: the access control or the second factor firewall answers before it runs when needed.
 */
final class PageController
{
    public function __invoke(Request $request): Response
    {
        return new Response($request->getPathInfo());
    }
}
