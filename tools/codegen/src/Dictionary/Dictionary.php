<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Dictionary;

use JmaXml\Codegen\Xlsx\XlsxReader;

/**
 * Japanese names and explanations for schema types, elements and attributes, read from the official dictionary.
 *
 * Each sheet is named after a namespace prefix (jmx, jmx_ib, ...). Below the header row (項番 in column A),
 * a row with a type name in column B starts that type; rows after it describe its child elements (column C)
 * or attributes (column D). Rows whose base type column E is "*" list enumeration values and are skipped.
 *
 * @author shimomo
 */
final class Dictionary
{
    /**
     * @param array<string, Description> $types "prefix type" => description
     * @param array<string, Description> $elements "prefix type element" => description
     * @param array<string, Description> $attributes "prefix type attribute" => description
     */
    private function __construct(
        private readonly array $types,
        private readonly array $elements,
        private readonly array $attributes,
    ) {
        //
    }

    public static function fromXlsx(string $path): self
    {
        $reader = XlsxReader::open($path);
        $types = [];
        $elements = [];
        $attributes = [];

        foreach ($reader->sheetNames() as $prefix) {
            if (str_starts_with($prefix, '#')) {
                continue;
            }

            $inBody = false;
            $type = null;

            foreach ($reader->rows($prefix) as $row) {
                if (!$inBody) {
                    $inBody = ($row['A'] ?? '') === '項番';

                    continue;
                }

                $description = new Description(self::clean($row['H'] ?? ''), self::clean($row['J'] ?? ''));

                if (isset($row['B'])) {
                    $type = $row['B'] === '(element)' ? null : $row['B'];

                    if ($type !== null) {
                        $types[$prefix . ' ' . $type] = $description;
                    }

                    continue;
                }

                if ($type === null || ($row['E'] ?? '') === '*') {
                    continue;
                }

                if (isset($row['D'])) {
                    $attributes[$prefix . ' ' . $type . ' ' . $row['D']] = $description;
                } elseif (isset($row['C'])) {
                    $elements[$prefix . ' ' . $type . ' ' . $row['C']] = $description;
                }
            }
        }

        return new self($types, $elements, $attributes);
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public function forType(string $prefix, string $typeName): ?Description
    {
        return $this->types[$prefix . ' ' . $typeName] ?? null;
    }

    public function forElement(string $prefix, string $typeName, string $elementName): ?Description
    {
        return $this->elements[$prefix . ' ' . $typeName . ' ' . $elementName] ?? null;
    }

    public function forAttribute(string $prefix, string $typeName, string $attributeName): ?Description
    {
        return $this->attributes[$prefix . ' ' . $typeName . ' ' . $attributeName] ?? null;
    }

    private static function clean(string $text): string
    {
        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $text);
    }
}
