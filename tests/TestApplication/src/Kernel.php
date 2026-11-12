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

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use IQ2i\HerseBundle\IQ2iHerseBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends SymfonyKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new IQ2iHerseBundle();

        if (self::usesDoctrine($this->getEnvironment())) {
            yield new DoctrineBundle();
            yield new TwigBundle();
        }
    }

    public function getCacheDir(): string
    {
        // Unique per process, so the container cache is rebuilt when a bundle source file changes between runs.
        return sys_get_temp_dir().'/herse/tests/var/'.$this->getEnvironment().'/cache/'.getmypid();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/herse/tests/var/'.$this->getEnvironment().'/log';
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('login', '/login')->controller(LoginController::class)->methods(['POST']);

        if (self::usesDoctrine($this->getEnvironment())) {
            $routes->add('reset', '/reset')->controller(ResetPasswordController::class)->methods(['POST']);
        }
    }

    protected function configureContainer(ContainerBuilder $containerBuilder, LoaderInterface $loader): void
    {
        $loader->load($this->getProjectDir().'/config/{packages}/*.php', 'glob');
        $loader->load($this->getProjectDir().'/config/{packages}/'.$this->getEnvironment().'/*.php', 'glob');
    }

    private static function usesDoctrine(string $environment): bool
    {
        return str_starts_with($environment, 'password_change_notification');
    }
}
