<?php

declare(strict_types=1);

namespace JmaXml\Tests\Support;

use LogicException;

/**
 * Locates the official JMA XML materials bundled in resources/jmaxml.
 *
 * @author shimomo
 */
final class Fixtures
{
    public static function resourcesDir(): string
    {
        return dirname(__DIR__, 4) . '/resources/jmaxml';
    }

    public static function xsdDir(): string
    {
        return self::resourcesDir() . '/xsd';
    }

    public static function dictionaryPath(): string
    {
        return self::resourcesDir() . '/dictionary.xlsx';
    }

    public static function samplesDir(): string
    {
        return self::resourcesDir() . '/samples';
    }

    public static function sample(string $fileName): string
    {
        $path = self::samplesDir() . '/' . $fileName;

        if (!is_file($path)) {
            throw new LogicException('No such sample: ' . $fileName);
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public static function samplePaths(): array
    {
        $paths = glob(self::samplesDir() . '/*.xml');

        if ($paths === false) {
            return [];
        }

        sort($paths, SORT_STRING);

        return $paths;
    }
}
