<?php

declare(strict_types=1);

namespace JmaXml\Earthquake\Tests;

use JmaXml\Earthquake\IntensityReading;
use JmaXml\Earthquake\SeismicIntensity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class IntensityReadingTest extends TestCase
{
    #[Test]
    public function readsConfirmedIntensity(): void
    {
        $reading = IntensityReading::from('0321000', '陸前高田市', '4', null);

        $this->assertSame(SeismicIntensity::Four, $reading->maxIntensity);
        $this->assertFalse($reading->notYetReceived);
        $this->assertTrue($reading->isAtLeast(SeismicIntensity::Four));
        $this->assertFalse($reading->isAtLeast(SeismicIntensity::FiveLower));
        $this->assertFalse($reading->mayBeAtLeast(SeismicIntensity::FiveLower));
    }

    #[Test]
    public function treatsNotYetReceivedAsPossiblyAnyIntensity(): void
    {
        $reading = IntensityReading::from('0338100', '金ケ崎町', null, '震度５弱以上未入電');

        $this->assertNull($reading->maxIntensity);
        $this->assertTrue($reading->notYetReceived);
        $this->assertFalse($reading->isAtLeast(SeismicIntensity::One));
        $this->assertTrue($reading->mayBeAtLeast(SeismicIntensity::Seven));
    }
}
