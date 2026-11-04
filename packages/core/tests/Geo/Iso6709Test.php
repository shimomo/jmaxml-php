<?php

declare(strict_types=1);

namespace JmaXml\Tests\Geo;

use InvalidArgumentException;
use JmaXml\Geo\Iso6709;
use JmaXml\Geo\Point;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class Iso6709Test extends TestCase
{
    #[Test]
    public function parsesDegreesWithDepth(): void
    {
        $this->assertEquals([new Point(34.8, 138.5, -10000.0)], Iso6709::parse('+34.8+138.5-10000/'));
    }

    #[Test]
    public function parsesNegativeCoordinatesWithLeadingZeroLongitude(): void
    {
        $this->assertEquals([new Point(-36.1, -72.6, null)], Iso6709::parse('-36.1-072.6/'));
    }

    #[Test]
    public function infersDegreeMinutesFromDigitCount(): void
    {
        $points = Iso6709::parse('+3624.38+13831.38/');

        $this->assertCount(1, $points);
        $this->assertEqualsWithDelta(36.0 + 24.38 / 60.0, $points[0]->latitude, 1e-9);
        $this->assertEqualsWithDelta(138.0 + 31.38 / 60.0, $points[0]->longitude, 1e-9);
        $this->assertNull($points[0]->altitude);
    }

    #[Test]
    public function typeAttributeWinsOverDigitCount(): void
    {
        $degreeMinutes = Iso6709::parse('+1750+14300/', '中心位置（度分）');
        $this->assertEqualsWithDelta(17.0 + 50.0 / 60.0, $degreeMinutes[0]->latitude, 1e-9);
        $this->assertEqualsWithDelta(143.0, $degreeMinutes[0]->longitude, 1e-9);

        $this->assertEquals(
            [new Point(41.0, 142.0, null), new Point(47.0, 152.0, null)],
            Iso6709::parse('+41+142/+47+152/', '位置（度）'),
        );
    }

    #[Test]
    public function parsesDegreeMinuteSecond(): void
    {
        $points = Iso6709::parse('+353000+1394500/', '位置（度分秒）');

        $this->assertEqualsWithDelta(35.5, $points[0]->latitude, 1e-9);
        $this->assertEqualsWithDelta(139.75, $points[0]->longitude, 1e-9);
    }

    #[Test]
    public function emptyValueHasNoPoints(): void
    {
        $this->assertSame([], Iso6709::parse(''));
        $this->assertSame([], Iso6709::parse('/'));
    }

    #[Test]
    public function rejectsOutOfRangeValues(): void
    {
        $rejected = 0;

        foreach ([['+91.0+135.0/', null], ['+35.0+181.0/', null], ['+3560.0+13500.0/', '位置（度分）'], ['+353060+1394500/', '位置（度分秒）']] as [$value, $type]) {
            try {
                Iso6709::parse($value, $type);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(4, $rejected);
    }

    #[Test]
    public function rejectsMalformedSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Iso6709::parse('north+135');
    }
}
