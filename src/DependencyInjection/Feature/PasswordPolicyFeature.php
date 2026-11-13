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

namespace IQ2i\HerseBundle\DependencyInjection\Feature;

use IQ2i\HerseBundle\Console\PasswordQuestionValidator;
use IQ2i\HerseBundle\DependencyInjection\Support;
use IQ2i\HerseBundle\Form\Extension\PasswordPolicyTypeExtension;
use IQ2i\HerseBundle\Form\Type\PasswordPolicyType;
use IQ2i\HerseBundle\PasswordPolicy\GuardedUserPasswordHasher;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyGuard;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicyValidator;
use IQ2i\HerseBundle\Validator\Mapping\PasswordPolicyLoader;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * The password policy: the validator, its automatic mapping on the user classes and the form type.
 *
 * @internal
 */
final class PasswordPolicyFeature
{
    public static function configure(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->integerNode('min_length')->defaultValue(12)->min(1)->end()
                ->integerNode('max_length')
                    ->info('Upper bound, so that oversized inputs are never hashed.')
                    ->defaultValue(PasswordHasherInterface::MAX_PASSWORD_LENGTH)
                    ->min(1)
                ->end()
                ->integerNode('required_character_types')
                    ->info('How many of the 4 types (lowercase, uppercase, digit, special) are required, 0 to disable the rule.')
                    ->defaultValue(4)
                    ->min(0)
                    ->max(4)
                ->end()
                ->booleanNode('check_compromised')
                    ->info('Reject passwords leaked in a data breach, with the native NotCompromisedPassword constraint.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('hash_guard')
                    ->info('Refuse to hash a password that breaks the policy, whatever the code path. Disable it where weak passwords are hashed on purpose, e.g. in the test environment.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('forms')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('auto')
                            ->info('Apply the policy to every PasswordType field with autocomplete="new-password". The "password_policy" option of a field overrides it.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('help')
                            ->info('Describe the policy in the help of these fields, unless they have one.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $policy): bool => $policy['max_length'] < $policy['min_length'])
                ->thenInvalid('iq2i_herse.password_policy: max_length must be greater than or equal to min_length.')
            ->end()
        ;
    }

    /**
     * @param array{enabled: bool, min_length: int, max_length: int, required_character_types: int, check_compromised: bool, hash_guard: bool, forms: array{auto: bool, help: bool}} $policy
     * @param array<class-string, array{property: string, groups: list<string>}>                                                                                                     $mapping
     */
    public static function load(array $policy, array $mapping, ContainerConfigurator $container): void
    {
        if ($policy['check_compromised'] && !ContainerBuilder::willBeAvailable('symfony/http-client', HttpClient::class, ['iq2i/herse-bundle'])) {
            throw new InvalidConfigurationException('iq2i_herse.password_policy.check_compromised requires the HTTP client. Try running "composer require symfony/http-client".');
        }

        $services = $container->services();

        $services->set(PasswordPolicyValidator::class)
            ->args([$policy['min_length'], $policy['max_length'], $policy['required_character_types'], $policy['check_compromised']])
            ->tag('validator.constraint_validator', ['alias' => PasswordPolicy::VALIDATOR_SERVICE])
        ;

        if ([] !== $mapping) {
            $services->set(PasswordPolicyLoader::class)->args([$mapping]);
        }

        // Public: the projects check a password, describe the policy and suspend the guard through them.
        $services->set(PasswordPolicyChecker::class)->args([new Reference('validator')])->public();
        $services->set(PasswordPolicyDescriber::class)
            ->args([$policy['min_length'], $policy['required_character_types'], new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->public()
        ;
        $services->set(PasswordPolicyGuard::class)->tag('kernel.reset', ['method' => 'reset'])->public();

        // Not registered when it is literally disabled; an environment variable is only known at runtime.
        if (false !== $policy['hash_guard']) {
            $services->set('iq2i_herse.password_policy.guarded_hasher', GuardedUserPasswordHasher::class)
                ->decorate('security.user_password_hasher', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
                ->args([new Reference('.inner'), new Reference(PasswordPolicyChecker::class), new Reference(PasswordPolicyGuard::class), $policy['hash_guard']])
            ;
        }

        if (class_exists(Command::class)) {
            $services->set(PasswordQuestionValidator::class)->args([new Reference(PasswordPolicyChecker::class)])->public();
        }

        if (ContainerBuilder::willBeAvailable('symfony/form', AbstractType::class, ['iq2i/herse-bundle'])) {
            $services->set(PasswordPolicyType::class)->tag('form.type');
            $services->set(PasswordPolicyTypeExtension::class)
                ->args([new Reference(PasswordPolicyChecker::class), new Reference(PasswordPolicyDescriber::class), $mapping, $policy['forms']['auto'], $policy['forms']['help']])
                ->tag('form.type_extension')
            ;
        }
    }

    /**
     * @param array<class-string, array{plain_password?: array{property?: string|null, groups?: list<string>}}> $users
     *
     * @return array<class-string, array{property: string, groups: list<string>}>
     */
    public static function mapping(array $users): array
    {
        $mapping = [];
        foreach ($users as $class => $user) {
            $property = $user['plain_password']['property'] ?? null;
            if (null === $property) {
                continue;
            }

            if (!Support::hasProperty($class, $property)) {
                throw new InvalidConfigurationException(\sprintf('iq2i_herse.users: class "%s" has no "%s" property.', $class, $property));
            }

            $mapping[$class] = ['property' => $property, 'groups' => $user['plain_password']['groups'] ?? ['Default']];
        }

        return $mapping;
    }
}
