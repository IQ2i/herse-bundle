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

namespace IQ2i\HerseBundle\Bridge\Moka\DependencyInjection;

use IQ2i\HerseBundle\Bridge\Moka\Generator\TwoFactorUserClassDumper;
use IQ2i\HerseBundle\Bridge\Moka\Mapping\TwoFactorUserMapper;
use Moka\Cms\Bridge\Doctrine\Mapping\Mapper\UserMapper;
use Moka\Cms\Generator\ClassDumper\UserClassDumper;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the classes of the two Moka services that generate and map the user class, whatever the order of the bundles.
 *
 * @internal
 */
final class MokaUserClassPass implements CompilerPassInterface
{
    public const PARAMETER = 'iq2i_herse.moka.second_factor';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }

        $container->getDefinition(UserClassDumper::class)->setClass(TwoFactorUserClassDumper::class);
        $container->getDefinition(UserMapper::class)->setClass(TwoFactorUserMapper::class);
    }
}
