<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests\Codes;

use JmaXml\Codegen\Codes\CodesGenerator;
use JmaXml\Codegen\Codes\EarthquakeCodeTables;
use JmaXml\Codegen\Generator;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fails when packages/codes/src/Data was not regenerated after a code table or generator change.
 *
 * Run `composer generate` to fix it.
 *
 * @author shimomo
 */
final class CodeDataIsUpToDateTest extends TestCase
{
    #[Test]
    public function committedCodeDataMatchesGenerator(): void
    {
        $sourceDir = dirname(__DIR__, 4) . '/packages/codes/src';
        $files = (new CodesGenerator(EarthquakeCodeTables::fromXlsx(Fixtures::earthquakeCodeTablePath())))->generate();

        $this->assertSame(
            [
                'Data/CityData.php',
                'Data/CodeTableVersion.php',
                'Data/EarthquakeAreaData.php',
                'Data/EpicenterAreaData.php',
                'Data/IntensityStationData.php',
                'Data/PrefectureData.php',
                'Data/TsunamiAreaData.php',
            ],
            array_keys($files),
        );

        foreach ($files as $path => $contents) {
            $this->assertStringContainsString(Generator::MARKER, $contents, $path);
            $this->assertStringEqualsFile($sourceDir . '/' . $path, $contents, $path);
        }
    }
}
