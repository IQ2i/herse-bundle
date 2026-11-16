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

namespace IQ2i\HerseBundle\Tests\Documentation;

use IQ2i\HerseBundle\IQ2iHerseBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The configuration trees written in the documentation name the options of the bundle, all of them and no other.
 */
final class ConfigurationReferenceTest extends TestCase
{
    /**
     * The sections keyed by a name of the project: their entries are examples, not options.
     */
    private const NAMED = ['users', 'firewalls', 'trusted_devices.firewalls'];

    #[DataProvider('provideTheReferences')]
    public function testTheReferenceNamesEveryOptionOfTheBundle(string $path): void
    {
        self::assertSame($this->optionsOfTheBundle(), $this->optionsOfTheReference($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheReferences(): iterable
    {
        yield 'guide' => [\dirname(__DIR__, 2).'/docs/configuration.md'];
        yield 'skill' => [\dirname(__DIR__, 2).'/skills/herse/references/configuration.md'];
    }

    /**
     * @return list<string>
     */
    private function optionsOfTheBundle(): array
    {
        $extension = (new IQ2iHerseBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        $options = $this->paths($configuration->getConfigTreeBuilder()->buildTree(), '');
        sort($options);

        return $options;
    }

    /**
     * @return list<string> the paths of the options, the name of a user class or of a firewall being a "*"
     */
    private function paths(NodeInterface $node, string $prefix): array
    {
        // A list of scalars is a value, as the validation groups.
        if ($node instanceof PrototypedArrayNode) {
            return $node->getPrototype() instanceof ArrayNode ? $this->paths($node->getPrototype(), $prefix.'.*') : [ltrim($prefix, '.')];
        }

        if (!$node instanceof ArrayNode || [] === $node->getChildren()) {
            return [ltrim($prefix, '.')];
        }

        $paths = [];
        foreach ($node->getChildren() as $name => $child) {
            $paths = [...$paths, ...$this->paths($child, $prefix.'.'.$name)];
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function optionsOfTheReference(string $path): array
    {
        if (1 !== preg_match('/```yaml\n(iq2i_herse:\n.*?)```/s', (string) file_get_contents($path), $matches)) {
            self::fail('A configuration tree was expected in '.$path);
        }

        $tree = Yaml::parse($matches[1]);
        self::assertIsArray($tree);
        self::assertIsArray($tree['iq2i_herse'] ?? null);

        $options = [];
        foreach ($tree['iq2i_herse'] as $section => $values) {
            self::assertIsString($section);
            $options = [...$options, ...$this->documented($values, $section)];
        }
        $options = array_values(array_unique($options));
        sort($options);

        return $options;
    }

    /**
     * @return list<string>
     */
    private function documented(mixed $values, string $prefix): array
    {
        if (!\is_array($values) || array_is_list($values)) {
            return [$prefix];
        }

        $options = [];
        foreach ($values as $name => $value) {
            $path = \in_array($prefix, self::NAMED, true) ? $prefix.'.*' : $prefix.'.'.$name;
            // An example left empty shows no option.
            if (str_ends_with($path, '.*') && null === $value) {
                continue;
            }
            $options = [...$options, ...$this->documented($value, $path)];
        }

        return $options;
    }
}
