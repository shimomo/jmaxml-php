<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Runs the release shell scripts from tests, in throwaway directories.
 *
 * @author shimomo
 */
final class Shell
{
    /**
     * Runs $command with sh -c in $cwd.
     *
     * @return array{int, string} the exit code, and standard output and standard error together
     */
    public static function run(string $cwd, string $command): array
    {
        $process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start sh');
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output === false ? '' : $output];
    }

    /**
     * The absolute path of bin/lib/<name>.sh.
     */
    public static function library(string $name): string
    {
        return dirname(__DIR__, 3) . '/bin/lib/' . $name . '.sh';
    }

    public static function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/jmaxml-release-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        return $directory;
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
