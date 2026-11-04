<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Sandbox;
use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Publishing one package to its split repository (package_release_publish in bin/lib/package-release.sh).
 *
 * @author shimomo
 */
final class PackageReleaseTest extends TestCase
{
    #[Test]
    public function publishRetriesAfterAnInterruptedRun(): void
    {
        $sandbox = Sandbox::create();

        try {
            // What a run that failed after the split leaves behind: the temporary tag and the split branch.
            [$setupExitCode, $setupOutput] = Shell::run($sandbox . '/repo', 'git tag pkg-core-0.1.0 && git branch split-core');
            $this->assertSame(0, $setupExitCode, $setupOutput);

            [$exitCode, $output] = Sandbox::run($sandbox, '. ' . escapeshellarg(Shell::library('package-release')) . '; package_release_publish 0.1.0 packages/core');

            $this->assertSame(0, $exitCode, $output);
            $remote = Shell::run($sandbox, 'git ls-remote jmaxml-php-core.git')[1];
            $this->assertStringContainsString('refs/heads/main', $remote);
            $this->assertStringContainsString('refs/tags/0.1.0', $remote);
            $this->assertSame([0, ''], Shell::run($sandbox . '/repo', "git tag -l 'pkg-*'; git branch --list 'split-*'"));
            $this->assertStringContainsString('gh release create 0.1.0 --repo shimomo/jmaxml-php-core', (string) file_get_contents($sandbox . '/gh.log'));
        } finally {
            Shell::remove($sandbox);
        }
    }
}
