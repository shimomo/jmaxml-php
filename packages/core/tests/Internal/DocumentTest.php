<?php

declare(strict_types=1);

namespace JmaXml\Tests\Internal;

use JmaXml\Exception\ParseException;
use JmaXml\Internal\Document;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class DocumentTest extends TestCase
{
    #[Test]
    public function returnsReportRootElement(): void
    {
        $root = Document::fromFile(Fixtures::sample('32-35_08_05_100915_VXSE51.xml'));

        $this->assertSame('Report', $root->localName);
        $this->assertSame(Document::REPORT_NAMESPACE, $root->namespaceURI);
    }

    #[Test]
    public function readsFromString(): void
    {
        $xml = file_get_contents(Fixtures::sample('32-35_08_05_100915_VXSE51.xml'));
        $this->assertIsString($xml);

        $this->assertSame('Report', Document::fromString($xml)->localName);
    }

    #[Test]
    #[DataProvider('invalidDocuments')]
    public function rejectsInvalidDocument(string $xml, string $reasonFragment): void
    {
        error_clear_last();

        try {
            Document::fromString($xml);
            $this->fail('ParseException was not thrown');
        } catch (ParseException $exception) {
            $this->assertSame('/', $exception->path);
            $this->assertStringContainsString($reasonFragment, $exception->getMessage());
        }

        $this->assertNull(error_get_last());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'empty' => ['', 'Empty document'];
        yield 'not xml' => ['not xml', 'Malformed XML'];
        yield 'doctype' => [
            '<?xml version="1.0"?><!DOCTYPE Report [<!ENTITY a "x">]><Report xmlns="http://xml.kishou.go.jp/jmaxml1/"/>',
            'DOCTYPE is not allowed',
        ];
        yield 'entity without doctype' => ['<?xml version="1.0"?><Report xmlns="http://xml.kishou.go.jp/jmaxml1/">&undefined;</Report>', 'Malformed XML'];
        yield 'nul byte' => ["<?xml version=\"1.0\"?><Report xmlns=\"http://xml.kishou.go.jp/jmaxml1/\">\0</Report>", 'Malformed XML'];
        yield 'invalid utf-8' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?><Report xmlns=\"http://xml.kishou.go.jp/jmaxml1/\">\xff</Report>", 'Malformed XML'];
        yield 'wrong root' => ['<?xml version="1.0"?><Feed xmlns="http://www.w3.org/2005/Atom"/>', 'Root element must be Report'];
        yield 'wrong namespace' => ['<?xml version="1.0"?><Report/>', 'Root element must be Report'];
    }

    #[Test]
    public function stopsEntityExpansionQuickly(): void
    {
        $entities = '<!ENTITY e0 "lol">';

        for ($i = 1; $i <= 10; $i++) {
            $entities .= '<!ENTITY e' . $i . ' "' . str_repeat('&e' . ($i - 1) . ';', 10) . '">';
        }

        $xml = '<?xml version="1.0"?><!DOCTYPE Report [' . $entities . ']><Report xmlns="http://xml.kishou.go.jp/jmaxml1/">&e10;</Report>';
        $started = microtime(true);

        try {
            Document::fromString($xml);
            $this->fail('ParseException was not thrown');
        } catch (ParseException $exception) {
            $this->assertSame('/', $exception->path);
        }

        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    #[Test]
    public function keepsLibxmlErrorsTheCallerAlreadyHad(): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            (new \DOMDocument())->loadXML('<broken');
            $before = count(libxml_get_errors());
            $this->assertGreaterThan(0, $before);

            Document::fromFile(Fixtures::sample('32-35_08_05_100915_VXSE51.xml'));

            $this->assertCount($before, libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    #[Test]
    public function unreadableFileDoesNotWarn(): void
    {
        error_clear_last();

        try {
            Document::fromFile(__DIR__);
            $this->fail('ParseException was not thrown');
        } catch (ParseException $exception) {
            $this->assertSame('/', $exception->path);
        }

        $this->assertNull(error_get_last());
    }

    #[Test]
    public function rejectsUnreadableFile(): void
    {
        error_clear_last();

        try {
            Document::fromFile('/nonexistent/jmaxml.xml');
            $this->fail('ParseException was not thrown');
        } catch (ParseException $exception) {
            $this->assertSame('/', $exception->path);
            $this->assertStringContainsString('Cannot read file', $exception->getMessage());
        }

        $this->assertNull(error_get_last());
    }
}
