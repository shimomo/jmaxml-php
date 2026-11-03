<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests;

use JmaXml\Codegen\GeneratedFiles;
use JmaXml\Codegen\Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class GeneratedFilesTest extends TestCase
{
    #[Test]
    public function writesFilesAndRemovesOnlyStaleGeneratedOnes(): void
    {
        $dir = sys_get_temp_dir() . '/jmaxml-generated-files-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/Stale.php', "<?php\n\n" . Generator::MARKER . "\n");
        file_put_contents($dir . '/Handwritten.php', "<?php\n");

        try {
            $written = GeneratedFiles::write($dir, ['Data/A.php' => "<?php\n\n" . Generator::MARKER . "\n"]);

            $this->assertSame(['Data/A.php'], $written);
            $this->assertFileDoesNotExist($dir . '/Stale.php');
            $this->assertFileExists($dir . '/Handwritten.php');
            $this->assertStringEqualsFile($dir . '/Data/A.php', "<?php\n\n" . Generator::MARKER . "\n");
        } finally {
            @unlink($dir . '/Data/A.php');
            @rmdir($dir . '/Data');
            @unlink($dir . '/Handwritten.php');
            @rmdir($dir);
        }
    }
}
