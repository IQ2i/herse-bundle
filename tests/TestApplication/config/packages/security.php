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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        'providers' => ['users' => ['memory' => ['users' => []]]],
        // Every firewall of the test environments is declared here: the security extension refuses firewalls added by another file.
        'firewalls' => [
            'admin' => ['pattern' => '^/admin', 'lazy' => true, 'provider' => 'users'],
            'public' => ['pattern' => '^/public', 'lazy' => true, 'provider' => 'users'],
            'main' => ['lazy' => true, 'provider' => 'users'],
        ],
    ]);
};
