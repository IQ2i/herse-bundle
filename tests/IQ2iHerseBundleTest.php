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

use IQ2i\HerseBundle\IQ2iHerseBundle;
use IQ2i\HerseBundle\Tests\TestApplication\Kernel;
use IQ2i\HerseBundle\Tests\TestApplication\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class IQ2iHerseBundleTest extends TestCase
{
    public function testTheKernelBootsWithEveryFeatureDisabled(): void
    {
        $handlerBeforeBoot = $this->currentExceptionHandler();
        $kernel = new Kernel('test', true);
        $kernel->boot();

        $container = $kernel->getContainer();

        self::assertSame(
            ['password_policy' => false, 'login_throttling' => false, 'trusted_devices' => false, 'password_change_notification' => false, 'two_factor' => false],
            $container->getParameter('iq2i_herse.features'),
        );
        self::assertSame(
            [User::class => ['type' => 'shop', 'email' => 'email', 'id' => 'id']],
            $container->getParameter('iq2i_herse.users'),
        );

        $kernel->shutdown();

        // Booting a debug kernel registers an exception handler on some Symfony versions, which PHPUnit reports as risky.
        while ($this->currentExceptionHandler() !== $handlerBeforeBoot) {
            restore_exception_handler();
        }
    }

    public function testNoServiceIsDeclaredWhileEveryFeatureIsDisabled(): void
    {
        $container = $this->load([]);

        self::assertSame([], array_filter(
            array_keys($container->getDefinitions() + $container->getAliases()),
            static fn (string $id): bool => str_starts_with($id, 'iq2i_herse.') || str_starts_with($id, 'IQ2i\\HerseBundle\\'),
        ));
    }

    public function testEachFeatureCanBeEnabled(): void
    {
        foreach (IQ2iHerseBundle::FEATURES as $feature) {
            /** @var array<string, bool> $features */
            $features = $this->load([$feature => true])->getParameter('iq2i_herse.features');

            self::assertSame([$feature], array_keys(array_filter($features)));
        }
    }

    public function testTheUserIdentifierPathDefaultsToId(): void
    {
        $users = $this->users(['users' => [User::class => ['type' => 'admin', 'email' => 'email']]]);

        self::assertSame('id', $users[User::class]['id']);
    }

    public function testTheUserIdentifierPathCanBeOverridden(): void
    {
        $users = $this->users(['users' => [User::class => ['type' => 'admin', 'email' => 'email', 'id' => 'uuid']]]);

        self::assertSame('uuid', $users[User::class]['id']);
    }

    public function testAnUnknownUserClassIsRejected(): void
    {
        $this->expectExceptionMessage('class "App\\Missing" does not exist');

        $this->load(['users' => ['App\\Missing' => ['type' => 'admin', 'email' => 'email']]]);
    }

    public function testADuplicatedUserTypeIsRejected(): void
    {
        $this->expectExceptionMessage('the type "admin" is used by several classes');

        $this->load(['users' => [
            User::class => ['type' => 'admin', 'email' => 'email'],
            \stdClass::class => ['type' => 'admin', 'email' => 'email'],
        ]]);
    }

    public function testAnEmptyTypeIsRejected(): void
    {
        $this->expectExceptionMessage('iq2i_herse.users.'.User::class.'.type');

        $this->load(['users' => [User::class => ['type' => '', 'email' => 'email']]]);
    }

    public function testTheEmailPathIsRequired(): void
    {
        $this->expectExceptionMessage('The child config "email" under "iq2i_herse.users.'.User::class.'" must be configured');

        $this->load(['users' => [User::class => ['type' => 'admin']]]);
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, array{type: string, email: string, id: string}>
     */
    private function users(array $config): array
    {
        /** @var array<string, array{type: string, email: string, id: string}> $users */
        $users = $this->load($config)->getParameter('iq2i_herse.users');

        return $users;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        // AbstractBundle's extension reads these on older Symfony versions, without guarding the lookup.
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => true,
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => __DIR__,
        ]));
        $extension = (new IQ2iHerseBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('iq2i_herse', $config);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->compile();

        return $container;
    }
}
