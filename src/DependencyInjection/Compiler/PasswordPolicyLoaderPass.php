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

namespace IQ2i\HerseBundle\DependencyInjection\Compiler;

use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The validator exposes no tag for metadata loaders: they are added to its builder.
 */
final class PasswordPolicyLoaderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(PasswordPolicyLoader::class) || !$container->hasDefinition('validator.builder')) {
            return;
        }

        $container->getDefinition('validator.builder')->addMethodCall('addLoader', [new Reference(PasswordPolicyLoader::class)]);
    }
}
