<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The CHANGELOG.md helpers of the release scripts (bin/lib/changelog.sh).
 *
 * @author shimomo
 */
final class ChangelogTest extends TestCase
{
    private const string DATE = '\d{4}-\d{2}-\d{2}';

    #[Test]
    public function handWrittenUnreleasedNotesBecomeTheRelease(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            file_put_contents($directory . '/CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n- jmaxml/core を追加\n");

            $this->assertSame(1, self::changelog($directory, 'changelog_unreleased_is_empty')[0]);
            $this->assertSame(0, self::changelog($directory, "changelog_stamp_release 0.1.0 ''")[0]);
            $this->assertMatchesRegularExpression(
                '/\A# Changelog\n\n## Unreleased\n\n## \[0\.1\.0\] - ' . self::DATE . '\n\n- jmaxml\/core を追加\n\z/u',
                (string) file_get_contents($directory . '/CHANGELOG.md'),
            );
        } finally {
            Shell::remove($directory);
        }
    }

    #[Test]
    public function commitSubjectsFillAnEmptyUnreleasedSection(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            file_put_contents($directory . '/CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n## [0.1.0] - 2026-11-04\n\n- jmaxml/core を追加\n");

            $this->assertSame(0, self::changelog($directory, 'changelog_unreleased_is_empty')[0]);
            $this->assertSame(0, self::changelog($directory, "changelog_stamp_release 0.1.1 '- fix: a'")[0]);
            $this->assertMatchesRegularExpression(
                '/\A# Changelog\n\n## Unreleased\n\n## \[0\.1\.1\] - ' . self::DATE . '\n\n- fix: a\n\n## \[0\.1\.0\] - 2026-11-04\n\n- jmaxml\/core を追加\n\z/u',
                (string) file_get_contents($directory . '/CHANGELOG.md'),
            );
        } finally {
            Shell::remove($directory);
        }
    }

    #[Test]
    public function backslashesInCommitSubjectsAreWrittenAsTheyAre(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            file_put_contents($directory . '/CHANGELOG.md', "# Changelog\n\n## Unreleased\n");

            $this->assertSame(0, self::changelog($directory, 'changelog_stamp_release 0.1.1 ' . escapeshellarg('- fix: escape \\n and \\t in C:\\path'))[0]);
            $this->assertStringContainsString("\n- fix: escape \\n and \\t in C:\\path\n", (string) file_get_contents($directory . '/CHANGELOG.md'));
        } finally {
            Shell::remove($directory);
        }
    }

    #[Test]
    public function releaseNotesAreTheSectionOfTheVersion(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            file_put_contents($directory . '/CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n## [0.1.1] - 2026-11-23\n\n- a\n- b\n\n## [0.1.0] - 2026-11-05\n\n- c\n");

            $this->assertSame([0, "- a\n- b\n"], self::changelog($directory, 'changelog_release_notes 0.1.1'));
            $this->assertSame([0, "- c\n"], self::changelog($directory, 'changelog_release_notes 0.1.0'));
        } finally {
            Shell::remove($directory);
        }
    }

    /**
     * @return array{int, string}
     */
    private static function changelog(string $directory, string $command): array
    {
        return Shell::run($directory, '. ' . escapeshellarg(Shell::library('changelog')) . '; ' . $command);
    }
}
