<?php

declare(strict_types=1);

namespace JmaXml\Earthquake\Tests;

use JmaXml\Earthquake\CityMatch;
use JmaXml\Earthquake\EarthquakeEvent;
use JmaXml\Earthquake\EventStage;
use JmaXml\Earthquake\MatchBasis;
use JmaXml\Earthquake\SeismicIntensity;
use JmaXml\Earthquake\Tests\Support\Samples;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class CityMatchingTest extends TestCase
{
    #[Test]
    public function expandsShindoSokuhoAreasToCities(): void
    {
        $matches = EarthquakeEvent::fromReports(Samples::earthquakeA())
            ->citiesAtLeast(SeismicIntensity::Four, ['0320100', '0320300', '0110100']);

        $this->assertEquals(
            [
                new CityMatch('0320100', SeismicIntensity::Four, false, MatchBasis::Area),
                new CityMatch('0320300', SeismicIntensity::Four, false, MatchBasis::Area),
            ],
            $matches->matches,
        );
        $this->assertSame([], $matches->unknownCityCodes);
    }

    #[Test]
    public function usesCityIntensitiesFromTheDetailedReport(): void
    {
        $matches = EarthquakeEvent::fromReports(Samples::earthquakeB())
            ->citiesAtLeast(SeismicIntensity::Four, ['0321000', '0320300']);

        $this->assertEquals([new CityMatch('0321000', SeismicIntensity::Four, false, MatchBasis::City)], $matches->matches);
    }

    #[Test]
    public function notYetReceivedCitiesMatchAnyThreshold(): void
    {
        $matches = EarthquakeEvent::fromReports(Samples::reports('32-35_06_01_240613_VXSE52.xml', '32-35_06_04_240613_VXSE53.xml'))
            ->citiesAtLeast(SeismicIntensity::Seven, ['0338100']);

        $this->assertEquals([new CityMatch('0338100', null, true, MatchBasis::City)], $matches->matches);
    }

    #[Test]
    public function notYetReceivedObservationPointsKeepACityOpenAboveItsConfirmedIntensity(): void
    {
        // 宮城加美町 is confirmed 5- but its point 中新田 has not reported yet (it later arrives as 5+).
        $kami = EarthquakeEvent::fromReports(Samples::reports('32-35_06_01_240613_VXSE52.xml', '32-35_06_03_240613_VXSE53.xml', '32-35_06_04_240613_VXSE53.xml'))
            ->citiesAtLeast(SeismicIntensity::FiveUpper, ['0444500']);

        $this->assertEquals([new CityMatch('0444500', SeismicIntensity::FiveLower, true, MatchBasis::City)], $kami->matches);

        // 藤枝市 is confirmed 5- in the last report while its point 岡出山 is still missing.
        $fujieda = EarthquakeEvent::fromReports(Samples::reports('32-35_07_06_240613_VXSE53.xml', '32-35_07_07_240613_VXSE53.xml'))
            ->citiesAtLeast(SeismicIntensity::FiveUpper, ['2221400']);

        $this->assertCount(1, $fujieda->matches);
        $this->assertTrue($fujieda->matches[0]->notYetReceived);
    }

    #[Test]
    public function unknownCityCodesAreReportedAfterTheDetailedReport(): void
    {
        $matches = EarthquakeEvent::fromReports(Samples::earthquakeB())
            ->citiesAtLeast(SeismicIntensity::Three, ['9999999', '0321000']);

        $this->assertSame(['0321000'], array_map(static fn(CityMatch $match): string => $match->cityCode, $matches->matches));
        $this->assertSame(['9999999'], $matches->unknownCityCodes);
    }

    #[Test]
    public function newerShindoSokuhoRaisesAreasAfterTheDetailedReport(): void
    {
        $before = ['32-35_04_02_100831_VXSE51.xml', '32-35_04_03_240613_VXSE52.xml', '32-35_04_04_240613_VXSE53.xml'];

        $this->assertSame([], EarthquakeEvent::fromReports(Samples::reports(...$before))->citiesAtLeast(SeismicIntensity::Four, ['4520100'])->matches);

        $after = EarthquakeEvent::fromReports(Samples::reports(...$before, ...['32-35_04_05_100831_VXSE51.xml']))
            ->citiesAtLeast(SeismicIntensity::Four, ['4520100']);

        $this->assertEquals([new CityMatch('4520100', SeismicIntensity::Four, false, MatchBasis::Area)], $after->matches);
    }

    #[Test]
    public function unknownCityCodesAreReported(): void
    {
        $matches = EarthquakeEvent::fromReports(Samples::earthquakeA())
            ->citiesAtLeast(SeismicIntensity::Three, ['9999999', '0320100']);

        $this->assertSame(['0320100'], array_map(static fn(CityMatch $match): string => $match->cityCode, $matches->matches));
        $this->assertSame(['9999999'], $matches->unknownCityCodes);
    }

    #[Test]
    public function cancelledEventMatchesNothing(): void
    {
        $matches = EarthquakeEvent::fromReports(Samples::reports(...Samples::cancelledSequence()))
            ->citiesAtLeast(SeismicIntensity::One, ['0338100']);

        $this->assertSame([], $matches->matches);
        $this->assertSame([], $matches->unknownCityCodes);
    }

    #[Test]
    public function cancellingOnlyTheDetailedReportFallsBackToShindoSokuho(): void
    {
        $cancelled = Samples::edited('32-35_04_04_240613_VXSE53.xml', [
            '<InfoType>発表</InfoType>' => '<InfoType>取消</InfoType>',
            '<ReportDateTime>2010-01-25T16:19:00+09:00</ReportDateTime>' => '<ReportDateTime>2010-01-25T16:21:00+09:00</ReportDateTime>',
        ]);
        $event = EarthquakeEvent::fromReports([
            ...Samples::reports('32-35_04_02_100831_VXSE51.xml', '32-35_04_03_240613_VXSE52.xml', '32-35_04_04_240613_VXSE53.xml'),
            $cancelled,
        ]);

        $this->assertSame(EventStage::WithHypocenter, $event->stage);
        $this->assertSame(['震源・震度に関する情報'], $event->cancelledKinds);
        $this->assertEquals(
            [new CityMatch('4520100', SeismicIntensity::Three, false, MatchBasis::Area)],
            $event->citiesAtLeast(SeismicIntensity::Three, ['4520100'])->matches,
        );
    }

    #[Test]
    public function detailedReportWithoutIntensityFallsBackToShindoSokuho(): void
    {
        $xml = (string) file_get_contents(\JmaXml\Tests\Support\Fixtures::sample('32-35_08_07_240613_VXSE53.xml'));
        $withoutIntensity = \JmaXml\Report::fromString((string) preg_replace('#<Intensity>.*</Intensity>#s', '', $xml));
        $event = EarthquakeEvent::fromReports([...Samples::reports('32-35_08_02_100915_VXSE51.xml', '32-35_08_04_100915_VXSE51.xml'), $withoutIntensity]);

        $this->assertEquals(
            [new CityMatch('0321000', SeismicIntensity::Four, false, MatchBasis::Area)],
            $event->citiesAtLeast(SeismicIntensity::Four, ['0321000'])->matches,
        );
    }

    #[Test]
    public function relatesTheTwoEarthquakesOfTheSecondSequence(): void
    {
        $first = EarthquakeEvent::fromReports(Samples::reports('32-35_04_01_100831_VXSE51.xml'));
        $second = EarthquakeEvent::fromReports(Samples::reports(
            '32-35_04_02_100831_VXSE51.xml',
            '32-35_04_03_240613_VXSE52.xml',
            '32-35_04_04_240613_VXSE53.xml',
            '32-35_04_05_100831_VXSE51.xml',
            '32-35_04_06_240613_VXSE53.xml',
        ));

        $this->assertTrue($first->isPossiblyRelatedTo($second));
    }

    #[Test]
    public function relationNeedsTheSameStatusAndLiveEvents(): void
    {
        $a = EarthquakeEvent::fromReports(Samples::earthquakeA());
        $drill = EarthquakeEvent::fromReports([Samples::edited('32-35_08_08_240613_VXSE53.xml', ['<Status>通常</Status>' => '<Status>訓練</Status>'])]);
        $cancelled = EarthquakeEvent::fromReports([
            ...Samples::reports('32-35_08_04_100915_VXSE51.xml'),
            Samples::edited('32-35_08_04_100915_VXSE51.xml', [
                '<InfoType>発表</InfoType>' => '<InfoType>取消</InfoType>',
                '<ReportDateTime>2010-07-05T06:58:00+09:00</ReportDateTime>' => '<ReportDateTime>2010-07-05T07:00:00+09:00</ReportDateTime>',
            ]),
        ]);

        $this->assertFalse($a->isPossiblyRelatedTo($drill));
        $this->assertFalse($a->isPossiblyRelatedTo($cancelled));
    }

    #[Test]
    public function relatesTheTwoEarthquakesOfTheSameShaking(): void
    {
        $a = EarthquakeEvent::fromReports(Samples::earthquakeA());
        $b = EarthquakeEvent::fromReports(Samples::earthquakeB());
        $tohoku = EarthquakeEvent::fromReports(Samples::reports('32-39_11_01_120615_VXSE51.xml', '32-39_11_05_240613_VXSE53.xml'));

        $this->assertTrue($a->isPossiblyRelatedTo($b));
        $this->assertTrue($b->isPossiblyRelatedTo($a));
        $this->assertFalse($a->isPossiblyRelatedTo($a));
        $this->assertFalse($a->isPossiblyRelatedTo($b, 30));
        $this->assertFalse($a->isPossiblyRelatedTo($tohoku));
    }
}
