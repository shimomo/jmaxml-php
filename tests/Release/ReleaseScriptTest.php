<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Sandbox;
use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/release.sh from end to end: prepare opens the pull request that bumps the version, publish tags main and
 * publishes the split repositories, and neither pushes to main.
 *
 * Each test builds a fresh Sandbox, whose fake composer and fake gh stand in for the real ones.
 *
 * @author shimomo
 */
final class ReleaseScriptTest extends TestCase
{
    #[Test]
    public function prepareOpensAPullRequestAndLeavesMainAlone(): void
    {
        $sandbox = Sandbox::create();

        try {
            $main = self::origin($sandbox, 'rev-parse refs/heads/main');

            [$exitCode, $output] = self::release($sandbox, 'prepare 0.1.0');

            $this->assertSame(0, $exitCode, $output);
            $this->assertSame($main, self::origin($sandbox, 'rev-parse refs/heads/main'));
            $this->assertSame("chore: prepare release 0.1.0\n", self::origin($sandbox, 'log -1 --no-show-signature --format=%s refs/heads/release/0.1.0'));
            $changelog = self::origin($sandbox, 'show refs/heads/release/0.1.0:CHANGELOG.md');
            $this->assertStringContainsString('## [0.1.0] - ', $changelog);
            $this->assertStringContainsString('- first', $changelog);
            $this->assertStringContainsString("'jmaxml-feed/0.1.0'", self::origin($sandbox, 'show refs/heads/release/0.1.0:packages/feed/src/FeedClient.php'));
            $this->assertStringContainsString('gh pr create --base main --head release/0.1.0', (string) file_get_contents($sandbox . '/gh.log'));
            $this->assertSame([0, "main\n"], Shell::run($sandbox . '/repo', 'git symbolic-ref --short HEAD'));
        } finally {
            Shell::remove($sandbox);
        }
    }

    #[Test]
    public function prepareRestoresAMissingUnreleasedHeading(): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', "printf '# Changelog\\n' > CHANGELOG.md && git commit -q -am x && git push -q origin main");
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$exitCode, $output] = self::release($sandbox, 'prepare 0.1.0');

            $this->assertSame(0, $exitCode, $output);
            $changelog = self::origin($sandbox, 'show refs/heads/release/0.1.0:CHANGELOG.md');
            $this->assertStringContainsString("## Unreleased\n", $changelog);
            $this->assertStringContainsString('## [0.1.0] - ', $changelog);
        } finally {
            Shell::remove($sandbox);
        }
    }

    #[Test]
    public function publishTagsMainWithoutPushingToIt(): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', Sandbox::PREPARED);
            $this->assertSame(0, $setupExitCode, $setupOutput);
            $main = self::origin($sandbox, 'rev-parse refs/heads/main');

            [$exitCode, $output] = self::release($sandbox, 'publish 0.1.0', "y\n");

            $this->assertSame(0, $exitCode, $output);
            $this->assertSame($main, self::origin($sandbox, 'rev-parse refs/heads/main'));
            $this->assertSame($main, self::origin($sandbox, 'rev-parse refs/tags/0.1.0^{commit}'));

            foreach (['core', 'feed'] as $package) {
                $this->assertStringContainsString('refs/tags/0.1.0', Shell::run($sandbox, 'git ls-remote jmaxml-php-' . $package . '.git')[1]);
            }
        } finally {
            Shell::remove($sandbox);
        }
    }

    #[Test]
    public function publishTagsTheReleaseCommitAfterMainHasMovedOn(): void
    {
        $sandbox = Sandbox::create();

        try {
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', Sandbox::PREPARED);
            $this->assertSame(0, $setupExitCode, $setupOutput);
            $release = self::origin($sandbox, 'rev-parse refs/heads/main');
            [$laterExitCode, $laterOutput] = Shell::run($sandbox . '/repo', 'echo later > packages/core/later.txt && git add . && git commit -q -m later && git push -q origin main');
            $this->assertSame(0, $laterExitCode, $laterOutput);

            [$exitCode, $output] = self::release($sandbox, 'publish 0.1.0', "y\n");

            $this->assertSame(0, $exitCode, $output);
            $this->assertSame($release, self::origin($sandbox, 'rev-parse refs/tags/0.1.0^{commit}'));
            $this->assertStringNotContainsString('later.txt', Shell::run($sandbox, 'git --git-dir=jmaxml-php-core.git ls-tree -r --name-only refs/tags/0.1.0')[1]);
        } finally {
            Shell::remove($sandbox);
        }
    }

    #[Test]
    public function publishReportsTheGitHubReleasesItCouldNotCreate(): void
    {
        $sandbox = Sandbox::create();

        try {
            // A gh that is logged in but cannot create releases.
            $gh = "printf '%s\\n' '#!/bin/sh' 'echo \"gh \$*\" >> \"\$(dirname \"\$0\")/../gh.log\"'"
                . " 'case \"\$1 \$2\" in \"release view\" | \"release create\") exit 1 ;; esac' > ../fake-bin/gh";
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', $gh . ' && ' . Sandbox::PREPARED);
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$exitCode, $output] = self::release($sandbox, 'publish 0.1.0', "y\n");

            $this->assertSame(1, $exitCode, $output);

            foreach (['core', 'feed'] as $package) {
                $this->assertStringContainsString('gh release create 0.1.0 --repo shimomo/jmaxml-php-' . $package . ' --generate-notes', $output);
                $this->assertStringContainsString('refs/tags/0.1.0', Shell::run($sandbox, 'git ls-remote jmaxml-php-' . $package . '.git')[1]);
            }
        } finally {
            Shell::remove($sandbox);
        }
    }

    #[Test]
    public function refusesTheOldSingleStepUsage(): void
    {
        $sandbox = Sandbox::create();

        try {
            $remote = Shell::run($sandbox, 'git ls-remote origin.git');

            [$exitCode, $output] = self::release($sandbox, '0.1.0');

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString('usage', $output);
            $this->assertSame($remote, Shell::run($sandbox, 'git ls-remote origin.git'));
            $this->assertSame([0, ''], Shell::run($sandbox . '/repo', 'git status --porcelain'));
        } finally {
            Shell::remove($sandbox);
        }
    }

    /**
     * Runs bin/release.sh with $arguments in the sandbox's repo/, with $input on its standard input.
     *
     * @return array{int, string}
     */
    private static function release(string $sandbox, string $arguments, string $input = ''): array
    {
        return Sandbox::run($sandbox, 'printf %s ' . escapeshellarg($input) . ' | ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/release.sh') . ' ' . $arguments);
    }

    /**
     * Runs git $command against the sandbox's origin.git and returns its output, failing the test when git fails.
     */
    private static function origin(string $sandbox, string $command): string
    {
        [$exitCode, $output] = Shell::run($sandbox, 'git --git-dir=origin.git ' . $command);
        self::assertSame(0, $exitCode, $output);

        return $output;
    }
}
