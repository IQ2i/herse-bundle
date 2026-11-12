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

use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorConfig;

return static function (ContainerConfigurator $container): void {
    TwoFactorConfig::load($container);
};
