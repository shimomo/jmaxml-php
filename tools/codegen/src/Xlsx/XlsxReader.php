<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Xlsx;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Minimal reader for the xlsx files JMA publishes (dictionary and code tables).
 *
 * Reads cell values only. Phonetic guides (<rPh>, furigana) are dropped from text.
 *
 * @author shimomo
 */
final class XlsxReader
{
    private const string MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const string PACKAGE_RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @param array<string, string> $sheetPaths sheet name => path inside the archive
     * @param list<string> $sharedStrings
     */
    private function __construct(
        private readonly ZipArchive $zip,
        private readonly array $sheetPaths,
        private readonly array $sharedStrings,
    ) {
        //
    }

    public static function open(string $path): self
    {
        $zip = new ZipArchive();

        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open xlsx file ' . $path);
        }

        $targets = [];
        $relationships = self::xpath(self::read($zip, 'xl/_rels/workbook.xml.rels'), 'p', self::PACKAGE_RELATIONSHIP_NS);

        foreach (self::elements($relationships, '//p:Relationship') as $relationship) {
            $target = $relationship->getAttribute('Target');
            $targets[$relationship->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }

        $sheetPaths = [];
        $workbook = self::xpath(self::read($zip, 'xl/workbook.xml'), 'm', self::MAIN_NS);

        foreach (self::elements($workbook, '//m:sheets/m:sheet') as $sheet) {
            $id = $sheet->getAttributeNS(self::RELATIONSHIP_NS, 'id');

            if (!isset($targets[$id])) {
                throw new RuntimeException('Missing relationship ' . $id . ' in ' . $path);
            }

            $sheetPaths[$sheet->getAttribute('name')] = $targets[$id];
        }

        $sharedStrings = [];

        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $strings = self::xpath(self::read($zip, 'xl/sharedStrings.xml'), 'm', self::MAIN_NS);

            foreach (self::elements($strings, '/m:sst/m:si') as $item) {
                $sharedStrings[] = self::text($strings, $item);
            }
        }

        return new self($zip, $sheetPaths, $sharedStrings);
    }

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return array_keys($this->sheetPaths);
    }

    /**
     * @return list<array<string, string>> each row maps a column letter to its value; empty cells and rows are omitted
     */
    public function rows(string $sheetName): array
    {
        if (!isset($this->sheetPaths[$sheetName])) {
            throw new RuntimeException('No such sheet ' . $sheetName);
        }

        $sheet = self::xpath(self::read($this->zip, $this->sheetPaths[$sheetName]), 'm', self::MAIN_NS);
        $rows = [];

        foreach (self::elements($sheet, '//m:sheetData/m:row') as $row) {
            $values = [];

            foreach (self::elements($sheet, 'm:c', $row) as $cell) {
                $value = $this->cellValue($sheet, $cell);

                if ($value !== '') {
                    $values[(string) preg_replace('/\d+$/', '', $cell->getAttribute('r'))] = $value;
                }
            }

            if ($values !== []) {
                $rows[] = $values;
            }
        }

        return $rows;
    }

    private function cellValue(DOMXPath $sheet, DOMElement $cell): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $inline = self::elements($sheet, 'm:is', $cell)[0] ?? null;

            return $inline === null ? '' : self::text($sheet, $inline);
        }

        $value = self::elements($sheet, 'm:v', $cell)[0]->textContent ?? '';

        if ($type === 's' && $value !== '') {
            return $this->sharedStrings[(int) $value] ?? '';
        }

        return $value;
    }

    private static function read(ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name);

        if ($contents === false) {
            throw new RuntimeException('Missing ' . $name . ' in xlsx file');
        }

        return $contents;
    }

    private static function xpath(string $xml, string $prefix, string $namespace): DOMXPath
    {
        $document = new DOMDocument();

        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Malformed XML in xlsx file');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace($prefix, $namespace);

        return $xpath;
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $expression, ?DOMElement $context = null): array
    {
        $elements = [];
        $nodes = $xpath->query($expression, $context);

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $elements[] = $node;
                }
            }
        }

        return $elements;
    }

    private static function text(DOMXPath $xpath, DOMElement $item): string
    {
        $text = '';

        foreach (self::elements($xpath, './/m:t[not(ancestor::m:rPh)]', $item) as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }
}
