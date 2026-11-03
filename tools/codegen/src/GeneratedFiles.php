<?php

declare(strict_types=1);

namespace JmaXml\Codegen;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Writes generated files and removes generated files that are no longer produced.
 *
 * Only .php files that carry Generator::MARKER are ever removed, so hand-written files next to generated ones are safe.
 *
 * @author shimomo
 */
final class GeneratedFiles
{
    /**
     * @param array<string, string> $files path relative to $outputDir => file contents
     *
     * @return list<string> written paths relative to $outputDir, sorted
     */
    public static function write(string $outputDir, array $files): array
    {
        ksort($files, SORT_STRING);

        foreach ($files as $path => $contents) {
            $target = $outputDir . '/' . $path;

            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Cannot create directory ' . dirname($target));
            }

            if (file_put_contents($target, $contents) === false) {
                throw new RuntimeException('Cannot write ' . $target);
            }
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $relative = substr($entry->getPathname(), strlen($outputDir) + 1);

            if ($entry->getExtension() !== 'php' || isset($files[$relative])) {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());

            if ($contents !== false && str_contains($contents, Generator::MARKER)) {
                unlink($entry->getPathname());
            }
        }

        return array_map('strval', array_keys($files));
    }
}
