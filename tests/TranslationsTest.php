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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Util\XliffUtils;

final class TranslationsTest extends TestCase
{
    #[DataProvider('provideTheCatalogues')]
    public function testTheCatalogueIsAValidXliffFileWithoutDuplicatedMessage(string $path): void
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load($path));
        self::assertSame([], XliffUtils::validateSchema($document));

        $sources = [];
        foreach ($document->getElementsByTagName('source') as $source) {
            $sources[] = $source->textContent;
        }
        self::assertSame($sources, array_values(array_unique($sources)));

        foreach ($document->getElementsByTagName('target') as $target) {
            self::assertNotSame('', trim($target->textContent));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheCatalogues(): iterable
    {
        $paths = glob(\dirname(__DIR__).'/translations/*.xlf') ?: [];
        self::assertNotSame([], $paths);

        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }
}
