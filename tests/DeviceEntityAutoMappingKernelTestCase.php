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

/**
 * With "auto_mapping", as in a Symfony recipe or Sylius, Doctrine maps the entities of every bundle that has an
 * "Entity" directory: the device table must not appear in a project that does not use the feature.
 */
abstract class DeviceEntityAutoMappingKernelTestCase extends DoctrineKernelTestCase
{
    /**
     * @return list<string>
     */
    protected function mappedClasses(): array
    {
        $classes = [];
        foreach ($this->entityManager()->getMetadataFactory()->getAllMetadata() as $metadata) {
            $classes[] = $metadata->getName();
        }

        return $classes;
    }
}
