<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The checks bin/release.sh runs before it changes or pushes anything (bin/lib/preflight.sh).
 *
 * Each test builds a sandbox: repo/ (branch main, pushed to origin.git) and the bare split repositories
 * jmaxml-php-core.git and jmaxml-php-feed.git next to it.
 *
 * @author shimomo
 */
final class PreflightTest extends TestCase
{
    private const string SANDBOX = <<<'SH'
        set -e
        git init -q --bare origin.git
        git init -q --bare jmaxml-php-core.git
        git init -q --bare jmaxml-php-feed.git
        git init -q -b main repo
        cd repo
        git config user.name test
        git config user.email test@example.com
        git config commit.gpgsign false
        git config tag.gpgSign false
        mkdir -p packages/core packages/feed
        echo '{}' > packages/core/composer.json
        echo '{}' > packages/feed/composer.json
        git add .
        git commit -q -m init
        git remote add origin "$(cd .. && pwd)/origin.git"
        git push -q origin main
        SH;

    #[Test]
    public function passesOnACleanMainInStepWithOrigin(): void
    {
        $sandbox = $this->sandbox();

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
        $sandbox = $this->sandbox();

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
    }

    private function sandbox(): string
    {
        $sandbox = Shell::temporaryDirectory();
        [$exitCode, $output] = Shell::run($sandbox, self::SANDBOX);
        $this->assertSame(0, $exitCode, $output);

        return $sandbox;
    }

    /**
     * @return array{int, string}
     */
    private static function preflight(string $sandbox, string $version): array
    {
        return Shell::run($sandbox . '/repo', 'export JMAXML_SPLIT_REMOTE_PREFIX=' . escapeshellarg($sandbox . '/jmaxml-php-')
            . '; . ' . escapeshellarg(Shell::library('package-release'))
            . '; . ' . escapeshellarg(Shell::library('preflight'))
            . '; release_preflight ' . escapeshellarg($version));
    }
}
