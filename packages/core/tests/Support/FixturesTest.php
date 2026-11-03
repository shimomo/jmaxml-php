<?php

declare(strict_types=1);

namespace JmaXml\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class FixturesTest extends TestCase
{
    #[Test]
    public function bundlesOfficialMaterials(): void
    {
        $xsdFiles = glob(Fixtures::xsdDir() . '/*.xsd');
        $this->assertIsArray($xsdFiles);
        $this->assertCount(8, $xsdFiles);
        $this->assertFileExists(Fixtures::xsdDir() . '/jmx.xsd');
        $this->assertFileExists(Fixtures::dictionaryPath());
        $this->assertCount(546, Fixtures::samplePaths());
        $this->assertSame(
            Fixtures::samplesDir() . '/32-35_01_03_240613_VXSE53.xml',
            Fixtures::sample('32-35_01_03_240613_VXSE53.xml'),
        );
    }
}
