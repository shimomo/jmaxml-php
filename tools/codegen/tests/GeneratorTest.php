<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests;

use DOMDocument;
use DOMElement;
use FilesystemIterator;
use JmaXml\Codegen\Dictionary\Dictionary;
use JmaXml\Codegen\Generator;
use JmaXml\Codegen\Schema\SchemaSet;
use JmaXml\Codegen\Tests\Generated\Mini\Item;
use JmaXml\Codegen\Tests\Generated\Mini\Root;
use JmaXml\Exception\ParseException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @author shimomo
 */
final class GeneratorTest extends TestCase
{
    #[Test]
    public function generatedClassesParseAMatchingDocument(): void
    {
        $root = Root::fromElement($this->miniRoot(), '/root');

        $this->assertSame('mini', $root->name);
        $this->assertSame('2026-11-03T12:00:00+09:00', $root->when?->format(DATE_ATOM));
        $this->assertSame(['A', 'B'], array_map(static fn(Item $item): string => $item->code, $root->item));
        $this->assertSame([1, 2], array_map(static fn(Item $item): int => $item->score, $root->item));
        $this->assertNull($root->size->value);
        $this->assertSame('m', $root->size->unit);
        $this->assertNull($root->size->condition);
        $this->assertSame('sample', $root->kind);
        $this->assertSame(['x', 'y'], $root->tag);
    }

    #[Test]
    public function committedMiniCodeMatchesGenerator(): void
    {
        foreach (self::miniGenerator()->generate() as $path => $contents) {
            $this->assertStringEqualsFile(__DIR__ . '/Generated/' . $path, $contents, $path);
        }
    }

    #[Test]
    public function reportsIndexedPathForMissingElementInsideRepeatedElement(): void
    {
        $element = $this->miniRoot(static fn(string $xml): string => str_replace('<Code>B</Code>', '', $xml));

        try {
            Root::fromElement($element, '/root');
            $this->fail('ParseException was not thrown');
        } catch (ParseException $exception) {
            $this->assertSame('/root/Item[2]/Code', $exception->path);
        }
    }

    #[Test]
    public function marksEveryGeneratedFile(): void
    {
        foreach (self::miniGenerator()->generate() as $contents) {
            $this->assertStringContainsString(Generator::MARKER, $contents);
        }
    }

    #[Test]
    public function writeToRemovesStaleGeneratedFilesOnly(): void
    {
        $dir = sys_get_temp_dir() . '/jmaxml-generator-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/Stale.php', "<?php\n\n" . Generator::MARKER . "\n");
        file_put_contents($dir . '/Handwritten.php', "<?php\n");

        try {
            $written = self::miniGenerator()->writeTo($dir);

            $this->assertSame(['Mini/Item.php', 'Mini/Root.php', 'Mini/Size.php'], $written);
            $this->assertFileDoesNotExist($dir . '/Stale.php');
            $this->assertFileExists($dir . '/Handwritten.php');
            $this->assertFileExists($dir . '/Mini/Root.php');
        } finally {
            self::removeDirectory($dir);
        }
    }

    #[Test]
    public function rejectsClassNamesThatDifferOnlyInCase(): void
    {
        $this->expectException(LogicException::class);

        (new Generator(SchemaSet::load(__DIR__ . '/Fixtures/case/case.xsd'), Dictionary::empty(), ['urn:case' => 'JmaXml\\Codegen\\Tests\\Generated\\CaseFixture'], 'JmaXml\\Codegen\\Tests'))->generate();
    }

    public static function miniGenerator(): Generator
    {
        return new Generator(
            SchemaSet::load(__DIR__ . '/Fixtures/mini/mini.xsd'),
            Dictionary::empty(),
            ['urn:mini' => 'JmaXml\Codegen\Tests\Generated\Mini'],
            'JmaXml\Codegen\Tests\Generated',
        );
    }

    /**
     * @param (callable(string): string)|null $edit
     */
    private function miniRoot(?callable $edit = null): DOMElement
    {
        $xml = file_get_contents(__DIR__ . '/Fixtures/mini/mini.xml');
        $this->assertIsString($xml);

        $document = new DOMDocument();
        $document->loadXML($edit === null ? $xml : $edit($xml));
        $root = $document->documentElement;
        $this->assertInstanceOf(DOMElement::class, $root);

        return $root;
    }

    private static function removeDirectory(string $dir): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
