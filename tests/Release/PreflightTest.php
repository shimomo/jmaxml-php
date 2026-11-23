<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Sandbox;
use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The checks bin/release.sh runs before it changes or pushes anything (bin/lib/preflight.sh), for the prepare step
 * (the pull request that bumps the version) and the publish step (the tag and the split repositories).
 *
 * Each test builds a fresh Sandbox.
 *
 * @author shimomo
 */
final class PreflightTest extends TestCase
{
    #[Test]
    #[DataProvider('readyStates')]
    public function passesOnACleanMainInStepWithOrigin(string $step, string $setup): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', $setup);
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$exitCode, $output] = self::preflight($sandbox, $step, '0.1.0');

            $this->assertSame(0, $exitCode, $output);
        } finally {
            Shell::remove($sandbox);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readyStates(): iterable
    {
        yield 'prepare' => ['prepare', 'true'];
        yield 'publish after the prepare pull request' => ['publish', Sandbox::PREPARED];
    }

    #[Test]
    #[DataProvider('brokenStates')]
    public function stopsBeforeAnythingIsPushed(string $step, string $setup, string $version, string $reason): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', $setup);
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$exitCode, $output] = self::preflight($sandbox, $step, $version);

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString($reason, $output);
        } finally {
            Shell::remove($sandbox);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function brokenStates(): iterable
    {
        yield 'bad version' => ['prepare', 'true', 'v0.1.0', 'version'];
        yield 'not on main' => ['prepare', 'git checkout -q -b topic', '0.1.0', 'main'];
        yield 'dirty tree' => ['prepare', 'echo x > untracked.txt', '0.1.0', 'clean'];
        yield 'tag on origin' => ['prepare', 'git tag 0.1.0 && git push -q origin 0.1.0 && git tag -d 0.1.0', '0.1.0', '0.1.0'];
        yield 'behind origin' => ['prepare', 'git commit -q --allow-empty -m x && git push -q origin main && git reset -q --hard HEAD~1', '0.1.0', 'origin/main'];
        yield 'missing split repo' => ['prepare', 'rm -rf ../jmaxml-php-feed.git', '0.1.0', 'jmaxml-php-feed'];
        yield 'not newer than the latest tag' => ['prepare', 'git tag 0.2.0', '0.1.5', 'newer than 0.2.0'];
        yield 'patch below the latest tag' => ['prepare', 'git tag 0.1.10', '0.1.9', 'newer than 0.1.10'];
        yield 'unknown step' => ['ship', 'true', '0.1.0', 'ship'];
        yield 'gh not logged in' => ['publish', "printf '%s\\n' '#!/bin/sh' 'exit 1' > ../fake-bin/gh", '0.1.0', 'gh auth login'];
        yield 'section already stamped' => ['prepare', Sandbox::PREPARED, '0.1.0', 'publish'];
        yield 'release branch on origin' => ['prepare', 'git push -q origin main:refs/heads/release/0.1.0', '0.1.0', 'git push origin --delete release/0.1.0'];
        yield 'prepare not merged' => ['publish', 'true', '0.1.0', 'prepare'];
        yield 'mutual require not bumped' => [
            'publish',
            "printf '%s\\n' '{\"require\": {\"jmaxml/core\": \"^0.0.1\"}}' > packages/feed/composer.json && " . Sandbox::PREPARED,
            '0.1.0',
            'packages/feed/composer.json',
        ];
    }

    #[Test]
    public function asksBeforePublishing(): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', 'git tag 0.0.9 && ' . Sandbox::PREPARED . ' && git commit -q --allow-empty -m later');
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$yes, $prompt] = self::confirm($sandbox, '0.1.0', 'y');
            $this->assertSame(0, $yes, $prompt);
            $this->assertStringContainsString('Release 0.1.0', $prompt);
            $this->assertStringContainsString('previous: 0.0.9', $prompt);
            $this->assertStringContainsString('chore: prepare release 0.1.0', $prompt);
            $this->assertStringContainsString('1 later commit', $prompt);

            $this->assertSame(1, self::confirm($sandbox, '0.1.0', 'n')[0]);
            $this->assertSame(1, self::confirm($sandbox, '0.1.0', '')[0]);
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
    private static function preflight(string $sandbox, string $step, string $version): array
    {
        return Sandbox::run($sandbox, '. ' . escapeshellarg(Shell::library('package-release'))
            . '; . ' . escapeshellarg(Shell::library('preflight'))
            . '; release_preflight ' . escapeshellarg($step) . ' ' . escapeshellarg($version));
    }
}
