<?php

declare(strict_types=1);

namespace JmaXml\Earthquake\Tests;

use JmaXml\Earthquake\SeismicIntensity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class SeismicIntensityTest extends TestCase
{
    #[Test]
    public function readsJmaValues(): void
    {
        $this->assertSame(SeismicIntensity::Four, SeismicIntensity::fromValue('4'));
        $this->assertSame(SeismicIntensity::Four, SeismicIntensity::fromValue('4 '));
        $this->assertSame(SeismicIntensity::FiveLower, SeismicIntensity::fromValue('5-'));
        $this->assertSame(SeismicIntensity::Seven, SeismicIntensity::fromValue('7'));

        foreach ([null, '', '震度５弱以上未入電', '8'] as $value) {
            $this->assertNull(SeismicIntensity::fromValue($value));
        }
    }

    #[Test]
    public function comparesByStrength(): void
    {
        $this->assertTrue(SeismicIntensity::FiveUpper->isAtLeast(SeismicIntensity::FiveLower));
        $this->assertFalse(SeismicIntensity::FiveLower->isAtLeast(SeismicIntensity::FiveUpper));
        $this->assertTrue(SeismicIntensity::Four->isAtLeast(SeismicIntensity::Four));
        $this->assertSame(9, SeismicIntensity::Seven->rank());
    }

    #[Test]
    public function labelsInJapanese(): void
    {
        $this->assertSame('震度5弱', SeismicIntensity::FiveLower->label());
        $this->assertSame('震度6強', SeismicIntensity::SixUpper->label());
        $this->assertSame('震度3', SeismicIntensity::Three->label());
    }
}
