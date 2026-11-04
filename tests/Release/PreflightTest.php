<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Sandbox;
use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The checks bin/release.sh runs before it changes or pushes anything (bin/lib/preflight.sh).
 *
 * Each test builds a fresh Sandbox.
 *
 * @author shimomo
 */
final class PreflightTest extends TestCase
{
    #[Test]
    public function passesOnACleanMainInStepWithOrigin(): void
    {
        $sandbox = Sandbox::create();

        try {
            [$exitCode, $output] = self::preflight($sandbox, '0.1.0');

            $this->assertSame(0, $exitCode, $output);
        } finally {
            Shell::remove($sandbox);
        }
    }

    #[Test]
    #[DataProvider('brokenStates')]
    public function stopsBeforeAnythingIsPushed(string $setup, string $version, string $reason): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', $setup);
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$exitCode, $output] = self::preflight($sandbox, $version);

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString($reason, $output);
        } finally {
            Shell::remove($sandbox);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function brokenStates(): iterable
    {
        yield 'bad version' => ['true', 'v0.1.0', 'version'];
        yield 'not on main' => ['git checkout -q -b topic', '0.1.0', 'main'];
        yield 'dirty tree' => ['echo x > untracked.txt', '0.1.0', 'clean'];
        yield 'tag on origin' => ['git tag 0.1.0 && git push -q origin 0.1.0 && git tag -d 0.1.0', '0.1.0', '0.1.0'];
        yield 'behind origin' => ['git commit -q --allow-empty -m x && git push -q origin main && git reset -q --hard HEAD~1', '0.1.0', 'origin/main'];
        yield 'missing split repo' => ['rm -rf ../jmaxml-php-feed.git', '0.1.0', 'jmaxml-php-feed'];
        yield 'not newer than the latest tag' => ['git tag 0.2.0', '0.1.5', 'newer than 0.2.0'];
        yield 'patch below the latest tag' => ['git tag 0.1.10', '0.1.9', 'newer than 0.1.10'];
    }

    #[Test]
    public function asksBeforePublishing(): void
    {
        $sandbox = Sandbox::create();

        try {
            [, $subject] = Shell::run($sandbox . '/repo', 'git tag 0.1.0 && git log -1 --format=%s');

            [$yes, $prompt] = self::confirm($sandbox, '0.1.1', 'y');
            $this->assertSame(0, $yes, $prompt);
            $this->assertStringContainsString('0.1.1', $prompt);
            $this->assertStringContainsString('previous: 0.1.0', $prompt);
            $this->assertStringContainsString(trim($subject), $prompt);

            $this->assertSame(1, self::confirm($sandbox, '0.1.1', 'n')[0]);
            $this->assertSame(1, self::confirm($sandbox, '0.1.1', '')[0]);
        } finally {
            Shell::remove($sandbox);
        }
    }

    /**
     * @return array{int, string}
     */
    private static function confirm(string $sandbox, string $version, string $answer): array
    {
        return Sandbox::run($sandbox, '. ' . escapeshellarg(Shell::library('package-release'))
            . '; . ' . escapeshellarg(Shell::library('preflight'))
            . '; printf %s ' . escapeshellarg($answer === '' ? '' : $answer . "\n") . ' | release_confirm ' . escapeshellarg($version));
    }

    /**
     * @return array{int, string}
     */
    private static function preflight(string $sandbox, string $version): array
    {
        return Sandbox::run($sandbox, '. ' . escapeshellarg(Shell::library('package-release'))
            . '; . ' . escapeshellarg(Shell::library('preflight'))
            . '; release_preflight ' . escapeshellarg($version));
    }
}
