<?php

declare(strict_types=1);

namespace JmaXml\Release\Tests;

use JmaXml\Release\Tests\Support\Shell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Writing the version into the default User-Agent of jmaxml/feed (bin/lib/user-agent.sh).
 *
 * @author shimomo
 */
final class UserAgentTest extends TestCase
{
    private const string FEED_CLIENT = 'packages/feed/src/FeedClient.php';

    #[Test]
    public function updateWritesTheVersionIntoTheRealFeedClient(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            $original = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::FEED_CLIENT);
            mkdir($directory . '/packages/feed/src', 0700, true);
            file_put_contents($directory . '/' . self::FEED_CLIENT, $original);

            [$exitCode, $output] = self::update($directory, '0.2.0');

            $this->assertSame(0, $exitCode, $output);
            $updated = (string) file_get_contents($directory . '/' . self::FEED_CLIENT);
            $this->assertStringContainsString("public const string DEFAULT_USER_AGENT = 'jmaxml-feed/0.2.0';", $updated);
            $this->assertSame(
                (string) preg_replace("/'jmaxml-feed\\/[^']*'/", "'jmaxml-feed/0.2.0'", $original),
                $updated,
                'only the version changes',
            );
        } finally {
            Shell::remove($directory);
        }
    }

    #[Test]
    public function updateStopsWhenTheLineIsMissing(): void
    {
        $directory = Shell::temporaryDirectory();

        try {
            mkdir($directory . '/packages/feed/src', 0700, true);
            file_put_contents($directory . '/' . self::FEED_CLIENT, "<?php\n\nfinal class FeedClient\n{\n}\n");

            [$exitCode, $output] = self::update($directory, '0.2.0');

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString('DEFAULT_USER_AGENT', $output);
            $this->assertSame("<?php\n\nfinal class FeedClient\n{\n}\n", file_get_contents($directory . '/' . self::FEED_CLIENT));
        } finally {
            Shell::remove($directory);
        }
    }

    /**
     * @return array{int, string}
     */
    private static function update(string $directory, string $version): array
    {
        return Shell::run($directory, '. ' . escapeshellarg(Shell::library('user-agent')) . '; user_agent_update ' . escapeshellarg($version));
    }
}
