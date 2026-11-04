<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The composer.json helpers of the release scripts (bin/lib/composer-*.sh) and the split remotes.
 *
 * @author shimomo
 */
final class ComposerScriptsTest extends TestCase
{
    #[Test]
    public function updatesMutualRequiresAndBranchAlias(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            $alias = ['branch-alias' => ['dev-main' => '0.1.x-dev']];
            self::write($directory . '/packages/core/composer.json', ['name' => 'jmaxml/core', 'extra' => $alias]);
            self::write($directory . '/packages/earthquake/composer.json', [
                'name' => 'jmaxml/earthquake',
                'require' => ['jmaxml/core' => '^0.1.0', 'psr/clock' => '^1.0'],
                'extra' => $alias,
            ]);

            // The libraries turn on set -e; it only applies to commands that are not part of an && list.
            [$exitCode, $output] = Shell::run($directory, '. ' . escapeshellarg(Shell::library('composer-version'))
                . '; composer_version_update_package_requires 0.2.0; composer_version_update_branch_alias 0.2.0');

            $this->assertSame(0, $exitCode, $output);
            $earthquake = self::read($directory . '/packages/earthquake/composer.json');
            $this->assertSame('^0.2.0', self::at($earthquake, 'require', 'jmaxml/core'));
            $this->assertSame('^1.0', self::at($earthquake, 'require', 'psr/clock'));
            $this->assertSame('0.2.x-dev', self::at($earthquake, 'extra', 'branch-alias', 'dev-main'));
            $this->assertSame('0.2.x-dev', self::at(self::read($directory . '/packages/core/composer.json'), 'extra', 'branch-alias', 'dev-main'));
            $this->assertStringStartsWith("{\n    \"name\"", (string) file_get_contents($directory . '/packages/earthquake/composer.json'));
        } finally {
            Shell::remove($directory);
        }
    }

    #[Test]
    public function mergeKeepsTheRootComposerJsonAsItIs(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = Shell::temporaryDirectory();

        try {
            copy($root . '/composer.json', $directory . '/composer.json');

            foreach ((array) glob($root . '/packages/*/composer.json') as $file) {
                $package = basename(dirname((string) $file));
                mkdir($directory . '/packages/' . $package, 0700, true);
                copy((string) $file, $directory . '/packages/' . $package . '/composer.json');
            }

            [$exitCode, $output] = Shell::run($directory, '. ' . escapeshellarg(Shell::library('composer-merge')) . '; composer_merge_packages_into_root');

            $this->assertSame(0, $exitCode, $output);
            $before = self::read($root . '/composer.json');
            $after = self::read($directory . '/composer.json');

            foreach (['require', 'require-dev', 'autoload', 'autoload-dev'] as $section) {
                $this->assertSame(self::sorted($before[$section] ?? null), self::sorted($after[$section] ?? null), $section);
            }
        } finally {
            Shell::remove($directory);
        }
    }

    #[Test]
    public function splitRemoteCanBePointedElsewhere(): void
    {
        $library = escapeshellarg(Shell::library('package-release'));

        $this->assertSame([0, "git@github.com:shimomo/jmaxml-php-core.git\n"], Shell::run(sys_get_temp_dir(), '. ' . $library . ' && package_release_remote core'));
        $this->assertSame(
            [0, "/tmp/x/jmaxml-php-feed.git\n"],
            Shell::run(sys_get_temp_dir(), 'export JMAXML_SPLIT_REMOTE_PREFIX=/tmp/x/jmaxml-php- && . ' . $library . ' && package_release_remote feed'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function write(string $path, array $data): void
    {
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function read(string $path): array
    {
        return self::asArray(json_decode((string) file_get_contents($path), true));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        if ($keys === []) {
            return $value;
        }

        $key = array_shift($keys);

        return is_array($value) ? self::at($value[$key] ?? null, ...$keys) : null;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::sorted(...), $value);
    }
}
