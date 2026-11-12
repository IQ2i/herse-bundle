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

namespace IQ2i\HerseBundle\DependencyInjection;

/**
 * Checks shared by the configuration of the features.
 *
 * @internal
 */
final class Support
{
    // Mirrors how RateLimiterFactory parses the interval, which fails on the first use otherwise.
    public static function isValidInterval(string $interval): bool
    {
        try {
            new \DateTimeImmutable('+'.$interval);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * property_exists() does not see a private property declared by a parent class, e.g. a mapped superclass.
     *
     * @param class-string $class
     */
    public static function hasProperty(string $class, string $property): bool
    {
        for ($reflection = new \ReflectionClass($class); false !== $reflection; $reflection = $reflection->getParentClass()) {
            if ($reflection->hasProperty($property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw configurations are not processed yet: the last one setting an option wins, as when they are merged.
     *
     * @param array<array<string, mixed>> $configs
     *
     * @return array<string, mixed> the options, with "enabled" always set
     */
    public static function rawFeature(array $configs, string $feature): array
    {
        /** @var array<string, mixed> $merged */
        $merged = ['enabled' => false];
        foreach ($configs as $config) {
            if (!\array_key_exists($feature, $config)) {
                continue;
            }

            $value = $config[$feature];
            /** @var array<string, mixed> $options */
            $options = \is_array($value) ? $value + ['enabled' => true] : ['enabled' => null === $value || (bool) $value];
            $merged = array_merge($merged, $options);
            $merged['enabled'] = (bool) $merged['enabled'];
        }

        return $merged;
    }
}
