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

    /**
     * @param array<string, array{login_throttling?: bool, verify?: bool}> $firewalls the declared firewalls, "main" being the only one of the project
     */
    private function container(array $firewalls, ?string $limiter = null, bool $loginThrottling = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('iq2i_herse.firewalls', array_map(static fn (array $firewall): array => $firewall + ['login_throttling' => true, 'verify' => true], $firewalls));
        $container->setParameter('iq2i_herse.features', ['login_throttling' => $loginThrottling]);
        $container->register('security.firewall.map.context.main');
        if (null !== $limiter) {
            $container->register('security.listener.login_throttling.main')->setArguments([new Reference('request_stack'), new Reference($limiter)]);
        }

        return $container;
    }
}
