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

use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\EmailAccount;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TotpAccount;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorScreensConfig;

return static function (ContainerConfigurator $container): void {
    // The trusted devices watch the declared firewalls: none is listed.
    TwoFactorScreensConfig::load($container, 'totp', TotpAccount::class, ['trusted_devices' => true], ['trusted_devices' => true], [EmailAccount::class => 'member']);
};
