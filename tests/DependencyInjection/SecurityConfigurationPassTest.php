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

namespace IQ2i\HerseBundle\Tests\DependencyInjection;

use IQ2i\HerseBundle\DependencyInjection\Compiler\SecurityConfigurationPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class SecurityConfigurationPassTest extends TestCase
{
    public function testNothingIsCheckedWithoutTheBundleConfiguration(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process(new ContainerBuilder());
    }

    public function testAFirewallUsingTheLimiterIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->container(['main' => []], limiter: 'iq2i_herse.login_throttling.limiter'));
    }

    public function testAnUnknownFirewallIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('the firewall "api" is not declared in the security configuration');

        (new SecurityConfigurationPass())->process($this->container(['api' => []]));
    }

    public function testAFirewallWithoutLoginThrottlingIsRejectedWithTheLinesToAdd(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("main:\n                login_throttling:\n                    limiter: iq2i_herse.login_throttling.limiter");

        (new SecurityConfigurationPass())->process($this->container(['main' => []]));
    }

    public function testAFirewallUsingTheNativeLimiterIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The firewall "main" does not use the login limiter of Herse');

        (new SecurityConfigurationPass())->process($this->container(['main' => []], limiter: 'security.login_throttling.main.limiter'));
    }

    public function testNativeLimitsLeftNextToTheLimiterAreRejected(): void
    {
        $container = $this->container(['main' => []], limiter: 'iq2i_herse.login_throttling.limiter');
        $container->prependExtensionConfig('security', ['firewalls' => ['main' => ['login_throttling' => ['max_attempts' => 3, 'interval' => '5 minutes']]]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"login_throttling.max_attempts", "login_throttling.interval": the latter is ignored');

        (new SecurityConfigurationPass())->process($container);
    }

    public function testTheLimiterIsNotRequiredWhileLoginThrottlingIsDisabled(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->container(['main' => []], loginThrottling: false));
    }

    public function testAFirewallCanBeLeftOutOfLoginThrottling(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->container(['main' => ['login_throttling' => false]]));
    }

    public function testTheChecksOfAFirewallCanBeDisabled(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->container(['api' => ['verify' => false]]));
    }

    public function testAFirewallAskingTheSecondFactorWithTheRoutesOfTheBundleIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->secondFactorContainer());
    }

    public function testAFirewallWithoutSecondFactorIsRejectedWithTheLinesToAdd(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The firewall "main" does not ask for the second factor');
        $this->expectExceptionMessage("two_factor:\n                    auth_form_path: iq2i_herse_main_two_factor_form\n                    check_path: iq2i_herse_main_two_factor_check\n                    enable_csrf: true");

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(options: null));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('provideForeignRoutes')]
    public function testAFirewallKeepingItsOwnSecondFactorRoutesIsRejected(array $options): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not use the second factor routes of Herse');

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(options: $options + self::OPTIONS));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideForeignRoutes(): iterable
    {
        yield 'form' => [['auth_form_path' => '/2fa']];
        yield 'check' => [['check_path' => '2fa_login_check']];
    }

    public function testASecondFactorFormWithoutCsrfProtectionIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not protect the second factor form against CSRF');

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(options: ['enable_csrf' => false] + self::OPTIONS));
    }

    public function testAFirewallWithoutLogoutIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('has no logout');

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(logout: false));
    }

    public function testAPathPrefixOutsideOfTheFirewallIsRejected(): void
    {
        $container = $this->secondFactorContainer(security: ['firewalls' => ['main' => ['pattern' => '%admin_pattern%']]]);
        $container->setParameter('admin_pattern', '^/admin');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not cover "/2fa", the path of the second factor form: check iq2i_herse.firewalls.main.path_prefix');

        (new SecurityConfigurationPass())->process($container);
    }

    public function testAPathPrefixInsideOfTheFirewallIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(pathPrefix: '/admin', security: ['firewalls' => ['main' => ['pattern' => '^/admin']]]));
    }

    /**
     * @param list<mixed> $rules
     */
    #[DataProvider('provideAccessRules')]
    public function testTheFirstAccessRuleMatchingTheFormMustLetTheSecondFactorIn(array $rules, ?string $problem): void
    {
        if (null === $problem) {
            $this->expectNotToPerformAssertions();
        } else {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage($problem);
        }

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(security: ['access_control' => $rules]));
    }

    /**
     * @return iterable<string, array{list<mixed>, string|null}>
     */
    public static function provideAccessRules(): iterable
    {
        $inProgress = ['path' => '^/2fa', 'roles' => 'IS_AUTHENTICATED_2FA_IN_PROGRESS'];

        yield 'no rule' => [[], null];
        yield 'the rule of the second factor' => [[$inProgress, ['path' => '^/', 'roles' => 'ROLE_USER']], null];
        yield 'the "role" spelling, among several roles' => [[['path' => '^/2fa', 'role' => 'ROLE_ADMIN, IS_AUTHENTICATED_2FA_IN_PROGRESS']], null];
        yield 'a list of roles' => [[['path' => '^/2fa', 'roles' => ['IS_AUTHENTICATED_2FA_IN_PROGRESS']]], null];
        yield 'rules about other paths' => [[['path' => '^/account', 'roles' => 'ROLE_USER'], null, $inProgress], null];
        yield 'a rule depending on the request is skipped' => [[['path' => '^/', 'roles' => 'ROLE_USER', 'ips' => ['127.0.0.1']], $inProgress], null];
        yield 'a broader rule first' => [[['path' => '^/', 'roles' => 'ROLE_USER'], $inProgress], 'has its second factor form "/2fa" closed by an access rule (path: ^/)'];
        yield 'a rule without a path first' => [[['roles' => 'ROLE_USER'], $inProgress], 'closed by an access rule (without a path)'];
    }

    public function testAFirewallCanBeLeftOutOfTheSecondFactor(): void
    {
        $this->expectNotToPerformAssertions();

        (new SecurityConfigurationPass())->process($this->secondFactorContainer(options: null, firewall: ['two_factor' => false]));
    }

    private const OPTIONS = ['auth_form_path' => 'iq2i_herse_main_two_factor_form', 'check_path' => 'iq2i_herse_main_two_factor_check', 'enable_csrf' => true];

    /**
     * @param array<string, array<string, mixed>> $firewalls the declared firewalls, "main" being the only one of the project
     */
    private function container(array $firewalls, ?string $limiter = null, bool $loginThrottling = true, bool $twoFactor = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('iq2i_herse.firewalls', array_map(static fn (array $firewall): array => $firewall + ['path_prefix' => '', 'login_throttling' => true, 'two_factor' => true, 'verify' => true], $firewalls));
        $container->setParameter('iq2i_herse.features', ['login_throttling' => $loginThrottling, 'two_factor' => $twoFactor]);
        $container->register('security.firewall.map.context.main');
        if (null !== $limiter) {
            $container->register('security.listener.login_throttling.main')->setArguments([new Reference('request_stack'), new Reference($limiter)]);
        }

        return $container;
    }

    /**
     * A project whose "main" firewall is declared for the second factor only.
     *
     * @param array<string, mixed>|null $options  the two_factor options of the firewall, as scheb resolved them
     * @param array<string, mixed>      $firewall the options of the firewall under iq2i_herse.firewalls
     * @param array<string, mixed>      $security what the project wrote under "security"
     */
    private function secondFactorContainer(?array $options = self::OPTIONS, bool $logout = true, string $pathPrefix = '', array $firewall = [], array $security = []): ContainerBuilder
    {
        $container = $this->container(['main' => $firewall + ['path_prefix' => $pathPrefix]], loginThrottling: false, twoFactor: true);
        if (null !== $options) {
            $container->register('security.firewall_config.two_factor.main')->setArguments([$options, 'main']);
        }
        if ($logout) {
            $container->register('security.logout_listener.main');
        }
        if ([] !== $security) {
            $container->prependExtensionConfig('security', $security);
        }

        return $container;
    }
}
