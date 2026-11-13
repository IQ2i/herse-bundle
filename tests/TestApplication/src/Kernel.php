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
use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
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

        if (self::usesScheb($this->getEnvironment())) {
            yield new SchebTwoFactorBundle();
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
        foreach (['login' => '/login', 'admin_login' => '/admin/login', 'public_login' => '/public/login'] as $name => $path) {
            $routes->add($name, $path)->controller(LoginController::class)->methods(['POST']);
        }

        if (self::usesDoctrine($this->getEnvironment())) {
            $routes->add('reset', '/reset')->controller(ResetPasswordController::class)->methods(['POST']);
        }

        if (str_starts_with($this->getEnvironment(), 'two_factor_screens')) {
            // What a project writes: one line importing the routes of the bundle.
            $routes->import('.', HerseRouteLoader::TYPE);
            $routes->add('admin_private', '/admin/private')->controller(PageController::class)->methods(['GET']);
        } elseif (str_starts_with($this->getEnvironment(), 'two_factor')) {
            $routes->add('two_factor_check', '/2fa_check')->controller(LoginController::class)->methods(['POST']);
            $routes->add('two_factor_form', '/2fa')->controller(PageController::class)->methods(['GET']);
        }

        if (str_starts_with($this->getEnvironment(), 'two_factor')) {
            $routes->add('private', '/private')->controller(PageController::class)->methods(['GET']);
        }
    }

    protected function configureContainer(ContainerBuilder $containerBuilder, LoaderInterface $loader): void
    {
        $loader->load($this->getProjectDir().'/config/{packages}/*.php', 'glob');
        $loader->load($this->getProjectDir().'/config/{packages}/'.$this->getEnvironment().'/*.php', 'glob');
    }

    private static function usesDoctrine(string $environment): bool
    {
        return str_starts_with($environment, 'password_change_notification') || str_starts_with($environment, 'trusted_devices') || str_starts_with($environment, 'two_factor');
    }

    /**
     * The environments reporting a scheb that is missing or not registered must not get it.
     */
    private static function usesScheb(string $environment): bool
    {
        return str_starts_with($environment, 'two_factor') && !\in_array($environment, ['two_factor_missing', 'two_factor_unregistered'], true) && class_exists(SchebTwoFactorBundle::class);
    }
}
