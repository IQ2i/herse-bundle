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

use IQ2i\HerseBundle\DependencyInjection\Compiler\PasswordPolicyLoaderPass;
use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use IQ2i\HerseBundle\IQ2iHerseBundle;
use IQ2i\HerseBundle\RateLimiter\LoginRateLimiter;
use IQ2i\HerseBundle\Tests\TestApplication\Kernel;
use IQ2i\HerseBundle\Tests\TestApplication\User;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicyValidator;
use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\Form;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

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

    public function testThePasswordPolicyDeclaresItsServicesWithTheDefaultSettings(): void
    {
        $container = $this->load(['password_policy' => true]);

        $validator = $container->getDefinition(PasswordPolicyValidator::class);
        self::assertSame([12, 4096, 4, false], $validator->getArguments());
        self::assertSame([['alias' => PasswordPolicy::VALIDATOR_SERVICE]], $validator->getTag('validator.constraint_validator'));
        self::assertSame(class_exists(Form::class), $container->hasDefinition(PasswordPolicyType::class));
        self::assertFalse($container->hasDefinition(PasswordPolicyLoader::class));
    }

    public function testThePasswordPolicySettingsAreConfigurable(): void
    {
        $container = $this->load(['password_policy' => ['min_length' => 8, 'max_length' => 64, 'required_character_types' => 2, 'check_compromised' => true]]);

        self::assertSame([8, 64, 2, true], $container->getDefinition(PasswordPolicyValidator::class)->getArguments());
    }

    /**
     * @param array<string, int> $policy
     */
    #[DataProvider('provideInvalidPasswordPolicies')]
    public function testAnInvalidPasswordPolicyIsRejected(array $policy, string $message): void
    {
        $this->expectExceptionMessage($message);

        $this->load(['password_policy' => $policy]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidPasswordPolicies(): iterable
    {
        yield 'max below min' => [['min_length' => 20, 'max_length' => 10], 'max_length must be greater than or equal to min_length'];
        yield 'no minimum' => [['min_length' => 0], 'iq2i_herse.password_policy.min_length'];
        yield 'too many types' => [['required_character_types' => 5], 'iq2i_herse.password_policy.required_character_types'];
        yield 'negative types' => [['required_character_types' => -1], 'iq2i_herse.password_policy.required_character_types'];
    }

    public function testThePlainPasswordShorthandAppliesToTheDefaultGroup(): void
    {
        $container = $this->load([
            'password_policy' => true,
            'users' => [User::class => ['type' => 'shop', 'email' => 'email', 'plain_password' => 'plainPassword']],
        ]);

        self::assertSame(
            [User::class => ['property' => 'plainPassword', 'groups' => ['Default']]],
            $container->getDefinition(PasswordPolicyLoader::class)->getArgument(0),
        );
    }

    public function testThePlainPasswordGroupsAreConfigurable(): void
    {
        $container = $this->load([
            'password_policy' => true,
            'users' => [User::class => ['type' => 'shop', 'email' => 'email', 'plain_password' => ['property' => 'plainPassword', 'groups' => ['sylius']]]],
        ]);

        self::assertSame(
            [User::class => ['property' => 'plainPassword', 'groups' => ['sylius']]],
            $container->getDefinition(PasswordPolicyLoader::class)->getArgument(0),
        );
    }

    public function testAnUnknownPlainPasswordPropertyIsRejected(): void
    {
        $this->expectExceptionMessage('has no "missing" property');

        $this->load([
            'password_policy' => true,
            'users' => [User::class => ['type' => 'shop', 'email' => 'email', 'plain_password' => 'missing']],
        ]);
    }

    public function testAPlainPasswordWithoutThePasswordPolicyIsRejected(): void
    {
        $this->expectExceptionMessage('"plain_password" requires iq2i_herse.password_policy to be enabled');

        $this->load(['users' => [User::class => ['type' => 'shop', 'email' => 'email', 'plain_password' => 'plainPassword']]]);
    }

    public function testThePasswordPolicyLoaderIsRegisteredOnTheValidatorBuilder(): void
    {
        $container = new ContainerBuilder();
        $container->register('validator.builder');
        $container->register(PasswordPolicyLoader::class)->setArguments([[]]);

        (new PasswordPolicyLoaderPass())->process($container);

        $calls = $container->getDefinition('validator.builder')->getMethodCalls();
        self::assertEquals([['addLoader', [new Reference(PasswordPolicyLoader::class)]]], $calls);
    }

    public function testThePasswordPolicyLoaderPassDoesNothingWithoutTheLoader(): void
    {
        $container = new ContainerBuilder();
        $container->register('validator.builder');

        (new PasswordPolicyLoaderPass())->process($container);

        self::assertSame([], $container->getDefinition('validator.builder')->getMethodCalls());
    }

    public function testThePasswordPolicyLoaderPassDoesNothingWithoutTheValidator(): void
    {
        $container = new ContainerBuilder();
        $container->register(PasswordPolicyLoader::class)->setArguments([[]]);

        (new PasswordPolicyLoaderPass())->process($container);

        self::assertFalse($container->has('validator.builder'));
    }

    public function testLoginThrottlingDeclaresItsServicesWithTheDefaultSettings(): void
    {
        $container = $this->load(['login_throttling' => true]);

        self::assertSame(CacheStorage::class, $container->getDefinition('iq2i_herse.login_throttling.storage')->getClass());
        self::assertEquals([new Reference('cache.rate_limiter')], $container->getDefinition('iq2i_herse.login_throttling.storage')->getArguments());

        self::assertSame(
            ['id' => 'iq2i_herse_login_identifier_and_ip', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'],
            $container->getDefinition('iq2i_herse.login_throttling.limiter.identifier_and_ip')->getArgument(0),
        );
        self::assertSame(
            ['id' => 'iq2i_herse_login_ip', 'policy' => 'fixed_window', 'limit' => 25, 'interval' => '1 minute'],
            $container->getDefinition('iq2i_herse.login_throttling.limiter.ip')->getArgument(0),
        );
        self::assertFalse($container->hasDefinition('iq2i_herse.login_throttling.limiter.identifier'));
        self::assertSame(RateLimiterFactory::class, $container->getDefinition('iq2i_herse.login_throttling.limiter.ip')->getClass());

        $limiter = $container->getDefinition('iq2i_herse.login_throttling.limiter');
        self::assertSame(LoginRateLimiter::class, $limiter->getClass());
        self::assertTrue($limiter->isPublic());
        self::assertNull($limiter->getArgument(2));
    }

    public function testLoginThrottlingSettingsAreConfigurable(): void
    {
        $container = $this->load(['login_throttling' => [
            'identifier_and_ip' => ['limit' => 3, 'interval' => '30 seconds'],
            'ip' => ['limit' => 10],
            'identifier' => ['limit' => 8],
            'cache_pool' => 'cache.shared',
        ]]);

        self::assertEquals([new Reference('cache.shared')], $container->getDefinition('iq2i_herse.login_throttling.storage')->getArguments());
        self::assertSame(['id' => 'iq2i_herse_login_identifier_and_ip', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '30 seconds'], $container->getDefinition('iq2i_herse.login_throttling.limiter.identifier_and_ip')->getArgument(0));
        self::assertSame(['id' => 'iq2i_herse_login_ip', 'policy' => 'fixed_window', 'limit' => 10, 'interval' => '1 minute'], $container->getDefinition('iq2i_herse.login_throttling.limiter.ip')->getArgument(0));
        self::assertSame(['id' => 'iq2i_herse_login_identifier', 'policy' => 'fixed_window', 'limit' => 8, 'interval' => '15 minutes'], $container->getDefinition('iq2i_herse.login_throttling.limiter.identifier')->getArgument(0));
    }

    public function testTheIdentifierLimitCanBeEnabledWithItsDefaults(): void
    {
        $container = $this->load(['login_throttling' => ['identifier' => true]]);

        /** @var array{limit: int} $options */
        $options = $container->getDefinition('iq2i_herse.login_throttling.limiter.identifier')->getArgument(0);

        self::assertSame(20, $options['limit']);
    }

    public function testTheIdentifierLimitCanBeDisabledExplicitly(): void
    {
        $container = $this->load(['login_throttling' => ['identifier' => ['enabled' => false, 'limit' => 8]]]);

        self::assertFalse($container->hasDefinition('iq2i_herse.login_throttling.limiter.identifier'));
    }

    public function testTheLockFactoryCanBeCustomizedOrDisabled(): void
    {
        $custom = $this->load(['login_throttling' => ['lock_factory' => 'app.lock']]);
        self::assertEquals(new Reference('app.lock'), $custom->getDefinition('iq2i_herse.login_throttling.limiter.ip')->getArgument(2));

        $disabled = $this->load(['login_throttling' => ['lock_factory' => null]]);
        self::assertNull($disabled->getDefinition('iq2i_herse.login_throttling.limiter.ip')->getArgument(2));
    }

    public function testTheLockFactoryIsOptionalInAutoMode(): void
    {
        $container = $this->load(['login_throttling' => true]);

        $lockFactory = $container->getDefinition('iq2i_herse.login_throttling.limiter.ip')->getArgument(2);
        if (class_exists(LockFactory::class)) {
            self::assertEquals(new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE), $lockFactory);
        } else {
            self::assertNull($lockFactory);
        }
    }

    /**
     * @param array<string, mixed> $throttling
     */
    #[DataProvider('provideInvalidLoginThrottlingSettings')]
    public function testInvalidLoginThrottlingSettingsAreRejected(array $throttling, string $message): void
    {
        $this->expectExceptionMessage($message);

        $this->load(['login_throttling' => $throttling]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidLoginThrottlingSettings(): iterable
    {
        yield 'no attempt allowed' => [['ip' => ['limit' => 0]], 'iq2i_herse.login_throttling.ip.limit'];
        yield 'unparsable interval' => [['identifier_and_ip' => ['interval' => 'soon']], '"soon" is not a valid interval'];
        yield 'empty interval' => [['identifier' => ['interval' => '']], 'iq2i_herse.login_throttling.identifier.interval'];
        yield 'empty cache pool' => [['cache_pool' => ''], 'iq2i_herse.login_throttling.cache_pool'];
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
            'kernel.secret' => 'secret',
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
