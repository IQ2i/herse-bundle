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

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        // Unique per process, so the container cache is rebuilt when a source file of the bundle changes between runs.
        return sys_get_temp_dir().'/herse/sylius/var/'.$this->getEnvironment().'/cache/'.getmypid();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/herse/sylius/var/'.$this->getEnvironment().'/log';
    }
}
