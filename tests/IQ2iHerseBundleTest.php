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

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use IQ2i\HerseBundle\Command\PurgeTrustedDevicesCommand;
use IQ2i\HerseBundle\DependencyInjection\Compiler\PasswordPolicyLoaderPass;
use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use IQ2i\HerseBundle\IQ2iHerseBundle;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeListener;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification;
use IQ2i\HerseBundle\RateLimiter\LoginRateLimiter;
use IQ2i\HerseBundle\Tests\TestApplication\Kernel;
use IQ2i\HerseBundle\Tests\TestApplication\User;
use IQ2i\HerseBundle\TrustedDevice\DeviceCookies;
use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceListener;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\TwoFactor\AuthCodeMailer;
use IQ2i\HerseBundle\TwoFactor\TrustedDeviceManager;
use IQ2i\HerseBundle\TwoFactor\TwoFactorEventRelay;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicyValidator;
use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\Form;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

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
            [User::class => ['type' => 'shop', 'email' => 'email', 'id' => 'id', 'password' => 'password', 'locale' => null]],
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
            if ('two_factor' === $feature && !class_exists(SchebTwoFactorBundle::class)) {
                continue;
            }

            $options = match ($feature) {
                'trusted_devices' => ['enabled' => true, 'firewalls' => ['main' => null]],
                'two_factor' => ['method' => 'totp'],
                default => true,
            };
            /** @var array<string, bool> $features */
            $features = $this->load([$feature => $options, 'mailer' => ['sender' => 'no-reply@example.com']])->getParameter('iq2i_herse.features');

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

    public function testPasswordChangeNotificationDeclaresItsServicesWithTheDefaultSettings(): void
    {
        $container = $this->load(['password_change_notification' => true, 'mailer' => ['sender' => 'no-reply@example.com']]);

        $listener = $container->getDefinition('iq2i_herse.password_change.listener');
        self::assertSame(PasswordChangeListener::class, $listener->getClass());
        self::assertSame([true, true, null], \array_slice($listener->getArguments(), -3));
        self::assertSame(['onFlush', 'postFlush', 'onClear'], array_column($listener->getTag('doctrine.event_listener'), 'event'));

        $window = $container->getDefinition('iq2i_herse.password_change.authentication_window');
        self::assertSame(
            [CheckPassportEvent::class, LoginSuccessEvent::class, LoginFailureEvent::class],
            array_column($window->getTag('kernel.event_listener'), 'event'),
        );
        self::assertCount(1, $window->getTag('kernel.reset'));

        self::assertTrue($container->getDefinition(PasswordChangeNotification::class)->isPublic());
        self::assertSame(['no-reply@example.com', null, null], \array_slice($container->getDefinition('iq2i_herse.mailer')->getArguments(), 3));
    }

    public function testPasswordChangeNotificationSettingsAreConfigurable(): void
    {
        $container = $this->load([
            'password_change_notification' => ['notify_on_other_user' => false, 'notify_on_console' => false],
            'mailer' => ['sender' => 'no-reply@example.com', 'sender_name' => 'Example', 'app_name' => 'Shop'],
        ]);

        self::assertSame([false, false, null], \array_slice($container->getDefinition('iq2i_herse.password_change.listener')->getArguments(), -3));
        self::assertSame(['no-reply@example.com', 'Example', 'Shop'], \array_slice($container->getDefinition('iq2i_herse.mailer')->getArguments(), 3));
    }

    public function testTheHashedPasswordPropertyCanBeConfiguredPerUserClass(): void
    {
        $container = $this->load([
            'password_change_notification' => true,
            'mailer' => ['sender' => 'no-reply@example.com'],
            'users' => [User::class => ['type' => 'shop', 'email' => 'email', 'password' => 'plainPassword', 'locale' => 'locale']],
        ]);

        /** @var array<class-string, array{password: string, locale: string|null}> $users */
        $users = $container->getDefinition('iq2i_herse.user_resolver')->getArgument(0);
        self::assertSame(['plainPassword', 'locale'], [$users[User::class]['password'], $users[User::class]['locale']]);
    }

    public function testPasswordChangeNotificationRequiresASender(): void
    {
        $this->expectExceptionMessage('iq2i_herse.mailer.sender is required');

        $this->load(['password_change_notification' => true]);
    }

    public function testPasswordChangeNotificationRequiresThePasswordPropertyOfEveryUserClass(): void
    {
        $this->expectExceptionMessage('has no "missing" property');

        $this->load([
            'password_change_notification' => true,
            'mailer' => ['sender' => 'no-reply@example.com'],
            'users' => [User::class => ['type' => 'shop', 'email' => 'email', 'password' => 'missing']],
        ]);
    }

    public function testAnEmptySenderIsRejected(): void
    {
        $this->expectExceptionMessage('iq2i_herse.mailer.sender');

        $this->load(['mailer' => ['sender' => '']]);
    }

    public function testAMissingPackageIsReportedWithTheComposerCommand(): void
    {
        $assert = new \ReflectionMethod(IQ2iHerseBundle::class, 'assertEmailRequirements');

        $this->expectExceptionMessage('iq2i_herse.password_change_notification requires acme/missing, acme/other. Try running "composer require acme/missing acme/other".');

        $assert->invoke(new IQ2iHerseBundle(), 'password_change_notification', ['acme/missing' => 'Acme\\Missing', 'acme/other' => 'Acme\\Other']);
    }

    public function testTrustedDevicesDeclareTheirServicesWithTheDefaultSettings(): void
    {
        $container = $this->load(['trusted_devices' => ['firewalls' => ['main' => null]], 'mailer' => ['sender' => 'no-reply@example.com']]);

        self::assertSame(DeviceCookies::class, $container->getDefinition('iq2i_herse.trusted_devices.cookies')->getClass());
        self::assertSame(
            [['main' => ['cookie_name' => 'herse_device_main', 'lifetime' => '30 days']]],
            $container->getDefinition('iq2i_herse.trusted_devices.cookies')->getArguments(),
        );
        self::assertSame([['event' => 'kernel.response', 'method' => 'onKernelResponse']], $container->getDefinition('iq2i_herse.trusted_devices.cookies')->getTag('kernel.event_listener'));

        $listener = $container->getDefinition('iq2i_herse.trusted_devices.listener');
        self::assertSame(TrustedDeviceListener::class, $listener->getClass());
        self::assertSame([true, true, false], \array_slice($listener->getArguments(), -3));
        self::assertSame([['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'priority' => 16]], $listener->getTag('kernel.event_listener'));

        self::assertTrue($container->getDefinition(TrustedDevices::class)->isPublic());
        self::assertTrue($container->getDefinition(PurgeTrustedDevicesCommand::class)->hasTag('console.command'));
        self::assertTrue($container->hasDefinition('iq2i_herse.mailer'));
    }

    public function testTrustedDevicesSettingsAreConfigurable(): void
    {
        $container = $this->load([
            'trusted_devices' => [
                'firewalls' => ['admin' => ['cookie_name' => 'admin.device', 'lifetime' => '7 days'], 'shop' => null],
                'store_ip' => false,
                'notify_first_device' => false,
                'notify_before_second_factor' => true,
            ],
            'mailer' => ['sender' => 'no-reply@example.com'],
        ]);

        self::assertSame(
            [['admin' => ['cookie_name' => 'admin.device', 'lifetime' => '7 days'], 'shop' => ['cookie_name' => 'herse_device_shop', 'lifetime' => '30 days']]],
            $container->getDefinition('iq2i_herse.trusted_devices.cookies')->getArguments(),
        );
        self::assertSame([false, false, true], \array_slice($container->getDefinition('iq2i_herse.trusted_devices.listener')->getArguments(), -3));
    }

    public function testTheDevicesAreRevokedOnPasswordChangeWhenAsked(): void
    {
        $container = $this->load([
            'trusted_devices' => ['firewalls' => ['main' => null], 'revoke_on_password_change' => true],
            'password_change_notification' => true,
            'mailer' => ['sender' => 'no-reply@example.com'],
        ]);

        self::assertEquals(new Reference(TrustedDevices::class), $container->getDefinition('iq2i_herse.password_change.listener')->getArgument(10));
    }

    public function testTheRevocationOnPasswordChangeIsIgnoredWhileTheFeatureIsDisabled(): void
    {
        $container = $this->load([
            'trusted_devices' => ['enabled' => false, 'revoke_on_password_change' => true],
            'password_change_notification' => true,
            'mailer' => ['sender' => 'no-reply@example.com'],
        ]);

        self::assertNull($container->getDefinition('iq2i_herse.password_change.listener')->getArgument(10));
        self::assertFalse($container->hasDefinition(TrustedDevices::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideInvalidTrustedDevices')]
    public function testInvalidTrustedDevicesAreRejected(array $config, string $message): void
    {
        $this->expectExceptionMessage($message);

        $this->load($config + ['mailer' => ['sender' => 'no-reply@example.com']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidTrustedDevices(): iterable
    {
        yield 'no firewall' => [['trusted_devices' => true], 'iq2i_herse.trusted_devices.firewalls requires at least one firewall'];
        yield 'invalid lifetime' => [['trusted_devices' => ['firewalls' => ['main' => ['lifetime' => 'forever']]]], 'is not a valid interval'];
        yield 'invalid cookie name' => [['trusted_devices' => ['firewalls' => ['main' => ['cookie_name' => 'a b;c']]]], 'is not a valid cookie name'];
        yield 'revocation without the notification' => [['trusted_devices' => ['firewalls' => ['main' => null], 'revoke_on_password_change' => true]], 'revoke_on_password_change requires iq2i_herse.password_change_notification'];
    }

    public function testTrustedDevicesRequireASender(): void
    {
        $this->expectExceptionMessage('iq2i_herse.mailer.sender is required');

        $this->load(['trusted_devices' => ['firewalls' => ['main' => null]]]);
    }

    /**
     * @param array<string, mixed> $herse
     */
    #[DataProvider('provideTheDeviceMappingCases')]
    public function testTheDeviceEntityIsMappedOnlyWhileTheFeatureIsEnabled(array $herse, bool $mapped): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $container->prependExtensionConfig('iq2i_herse', $herse);

        $extension = (new IQ2iHerseBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);

        /** @var array{orm?: array{mappings?: array<string, mixed>}} $doctrine */
        $doctrine = $container->getExtensionConfig('doctrine')[0] ?? [];
        $mappings = $doctrine['orm']['mappings'] ?? [];
        if ($mapped) {
            self::assertSame(['type' => 'attribute', 'is_bundle' => false, 'dir' => \dirname(__DIR__).'/src/Entity', 'prefix' => 'IQ2i\\HerseBundle\\Entity'], $mappings['IQ2iHerse']);
        } else {
            self::assertSame([], $mappings);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function provideTheDeviceMappingCases(): iterable
    {
        yield 'enabled by a flag' => [['trusted_devices' => true], true];
        yield 'enabled by its options' => [['trusted_devices' => ['firewalls' => ['main' => null]]], true];
        yield 'enabled by an empty value' => [['trusted_devices' => null], true];
        yield 'enabled explicitly' => [['trusted_devices' => ['enabled' => true]], true];
        yield 'disabled' => [['trusted_devices' => false], false];
        yield 'disabled explicitly' => [['trusted_devices' => ['enabled' => false, 'firewalls' => ['main' => null]]], false];
        yield 'not configured' => [['password_policy' => true], false];
        yield 'no configuration' => [[], false];
    }

    public function testTheDeviceEntityIsNotMappedWithoutDoctrine(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('iq2i_herse', ['trusted_devices' => true]);

        $extension = (new IQ2iHerseBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testTheSecondFactorMethodIsRequired(): void
    {
        $this->expectExceptionMessage('iq2i_herse.two_factor.method is required');

        $this->load(['two_factor' => true]);
    }

    public function testAnUnknownSecondFactorMethodIsRejected(): void
    {
        $this->expectExceptionMessage('"sms"');

        $this->load(['two_factor' => ['method' => 'sms']]);
    }

    public function testAMissingSchebIsReportedWithTheComposerCommand(): void
    {
        if (class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('Only meaningful while scheb is not installed.');
        }

        $this->expectExceptionMessage('iq2i_herse.two_factor requires scheb/2fa-bundle, scheb/2fa-email. Try running "composer require scheb/2fa-bundle scheb/2fa-email".');

        $this->load(['two_factor' => ['method' => 'email'], 'mailer' => ['sender' => 'no-reply@example.com']]);
    }

    public function testTheComposerCommandNamesThePackagesOfTheMethod(): void
    {
        if (class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('Only meaningful while scheb is not installed.');
        }

        $this->expectExceptionMessage('Try running "composer require scheb/2fa-bundle scheb/2fa-totp scheb/2fa-trusted-device".');

        $this->load(['two_factor' => ['method' => 'totp', 'trusted_devices' => true]]);
    }

    public function testTheSchebBundleMustBeRegistered(): void
    {
        $this->requireScheb();

        $this->expectExceptionMessage('requires the bundle "Scheb\\TwoFactorBundle\\SchebTwoFactorBundle" to be registered');

        $this->load(['two_factor' => ['method' => 'totp']], []);
    }

    public function testTheSecondFactorByEmailDeclaresItsServices(): void
    {
        $this->requireScheb();

        $container = $this->load(['two_factor' => ['method' => 'email'], 'mailer' => ['sender' => 'no-reply@example.com']]);

        self::assertSame(AuthCodeMailer::class, $container->getDefinition('iq2i_herse.two_factor.auth_code_mailer')->getClass());
        self::assertSame(TwoFactorEventRelay::class, $container->getDefinition('iq2i_herse.two_factor.event_relay')->getClass());
        self::assertSame(
            [['event' => 'scheb_two_factor.authentication.success', 'method' => 'onSuccess'], ['event' => 'scheb_two_factor.authentication.failure', 'method' => 'onFailure']],
            $container->getDefinition('iq2i_herse.two_factor.event_relay')->getTag('kernel.event_listener'),
        );
        self::assertFalse($container->hasDefinition('iq2i_herse.two_factor.trusted_device_manager'));
    }

    public function testTheSecondFactorByEmailRequiresASender(): void
    {
        $this->requireScheb();

        $this->expectExceptionMessage('iq2i_herse.mailer.sender is required');

        $this->load(['two_factor' => ['method' => 'email']]);
    }

    public function testTheSecondFactorByTotpSendsNoEmail(): void
    {
        $this->requireScheb();

        $container = $this->load(['two_factor' => ['method' => 'totp']]);

        self::assertTrue($container->hasDefinition('iq2i_herse.two_factor.event_relay'));
        self::assertFalse($container->hasDefinition('iq2i_herse.two_factor.auth_code_mailer'));
        self::assertFalse($container->hasDefinition('iq2i_herse.mailer'));
    }

    public function testTheDeviceBridgeDeclaresTheTrustedDeviceManager(): void
    {
        $this->requireScheb();

        $container = $this->load([
            'two_factor' => ['method' => 'totp', 'trusted_devices' => true],
            'trusted_devices' => ['firewalls' => ['main' => null]],
            'mailer' => ['sender' => 'no-reply@example.com'],
        ]);

        self::assertSame(TrustedDeviceManager::class, $container->getDefinition('iq2i_herse.two_factor.trusted_device_manager')->getClass());
    }

    public function testTheDeviceBridgeRequiresTheTrustedDevices(): void
    {
        $this->requireScheb();

        $this->expectExceptionMessage('iq2i_herse.two_factor.trusted_devices requires iq2i_herse.trusted_devices to be enabled');

        $this->load(['two_factor' => ['method' => 'totp', 'trusted_devices' => true]]);
    }

    /**
     * @param list<array<string, mixed>>          $configs
     * @param array<string, array<string, mixed>> $expected
     */
    #[DataProvider('provideTheSchebDefaults')]
    public function testSchebReceivesTheDefaultsOfTheMethod(array $configs, array $expected): void
    {
        $this->requireScheb();

        $container = $this->newContainer();
        $herse = (new IQ2iHerseBundle())->getContainerExtension();
        $scheb = (new SchebTwoFactorBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $herse);
        self::assertNotNull($scheb);
        $container->registerExtension($herse);
        $container->registerExtension($scheb);
        foreach ($configs as $config) {
            $container->loadFromExtension('iq2i_herse', $config);
        }
        $herse->prepend($container);

        self::assertSame([] === $expected ? [] : [$expected], $container->getExtensionConfig('scheb_two_factor'));
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, array<string, array<string, mixed>>}>
     */
    public static function provideTheSchebDefaults(): iterable
    {
        $email = ['email' => ['enabled' => true, 'mailer' => 'iq2i_herse.two_factor.auth_code_mailer', 'digits' => 6]];

        yield 'email' => [[['two_factor' => ['method' => 'email']]], $email];
        yield 'email enabled by its method only' => [[['two_factor' => ['enabled' => true, 'method' => 'email']]], $email];
        yield 'totp without application name' => [[['two_factor' => ['method' => 'totp']]], ['totp' => ['enabled' => true]]];
        yield 'totp with the application name as issuer' => [[['two_factor' => ['method' => 'totp'], 'mailer' => ['app_name' => 'Acme']]], ['totp' => ['enabled' => true, 'issuer' => 'Acme']]];
        yield 'the last application name wins' => [[['mailer' => ['app_name' => 'Old']], ['two_factor' => ['method' => 'totp'], 'mailer' => ['app_name' => 'Acme']]], ['totp' => ['enabled' => true, 'issuer' => 'Acme']]];
        yield 'with the device bridge' => [[['two_factor' => ['method' => 'email', 'trusted_devices' => true]]], $email + ['trusted_device' => ['enabled' => true, 'manager' => 'iq2i_herse.two_factor.trusted_device_manager']]];
        yield 'the method of the last configuration wins' => [[['two_factor' => ['method' => 'email']], ['two_factor' => ['method' => 'totp']]], ['totp' => ['enabled' => true]]];
        yield 'disabled' => [[['two_factor' => false]], []];
        yield 'disabled by a later configuration' => [[['two_factor' => ['method' => 'email']], ['two_factor' => false]], []];
        yield 'no method' => [[['two_factor' => true]], []];
        yield 'not configured' => [[['password_policy' => true]], []];
    }

    public function testNothingIsPrependedToSchebWhenItsBundleIsMissing(): void
    {
        $container = $this->newContainer();
        $container->prependExtensionConfig('iq2i_herse', ['two_factor' => ['method' => 'email']]);

        $extension = (new IQ2iHerseBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('scheb_two_factor'));
    }

    private function requireScheb(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }
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
     * @param array<string, string>|null $bundles the registered bundles, the ones of a project using scheb by default
     */
    private function newContainer(?array $bundles = null): ContainerBuilder
    {
        // AbstractBundle's extension reads these on older Symfony versions, without guarding the lookup.
        return new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => true,
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => __DIR__,
            'kernel.secret' => 'secret',
            'kernel.bundles' => $bundles ?? ['IQ2iHerseBundle' => IQ2iHerseBundle::class, 'SchebTwoFactorBundle' => SchebTwoFactorBundle::class],
        ]));
    }

    /**
     * @param array<string, mixed>       $config
     * @param array<string, string>|null $bundles the registered bundles, the ones of a project using scheb by default
     */
    private function load(array $config, ?array $bundles = null): ContainerBuilder
    {
        $container = $this->newContainer($bundles);
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
