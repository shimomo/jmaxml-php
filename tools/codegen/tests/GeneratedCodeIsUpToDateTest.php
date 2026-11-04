<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests;

use FilesystemIterator;
use JmaXml\Codegen\Dictionary\Dictionary;
use JmaXml\Codegen\Generator;
use JmaXml\Codegen\Schema\SchemaSet;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fails when packages/core/src was not regenerated after a schema, dictionary or generator change.
 *
 * Run `composer generate` to fix it.
 *
 * @author shimomo
 */
final class GeneratedCodeIsUpToDateTest extends TestCase
{
    #[Test]
    public function committedCodeMatchesGenerator(): void
    {
        $sourceDir = dirname(__DIR__, 3) . '/packages/core/src';
        $files = (new Generator(
            SchemaSet::load(Fixtures::xsdDir() . '/jmx.xsd'),
            Dictionary::fromXlsx(Fixtures::dictionaryPath()),
            Generator::defaultNamespaceMap(),
        ))->generate();

        $this->assertCount(274, $files);

        foreach ($files as $path => $contents) {
            $this->assertStringEqualsFile($sourceDir . '/' . $path, $contents, $path);
        }

        $this->assertSame(array_keys($files), self::markedFiles($sourceDir));
    }

    #[Test]
    public function referencedElementsCarryTheirDictionaryDescription(): void
    {
        $earthquake = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/core/src/Seismology/Earthquake.php');

        $this->assertStringContainsString('@var list<Magnitude> マグニチュード', $earthquake);
    }

    /**
     * @return list<string> paths relative to $dir of files carrying the generated-code marker, sorted
     */
    private static function markedFiles(string $dir): array
    {
        $marked = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());

            if ($file->getExtension() === 'php' && $contents !== false && str_contains($contents, Generator::MARKER)) {
                $marked[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }

        sort($marked, SORT_STRING);

        return $marked;
    }
}
