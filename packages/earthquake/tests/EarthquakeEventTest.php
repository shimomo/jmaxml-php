<?php

declare(strict_types=1);

namespace JmaXml\Earthquake\Tests;

use InvalidArgumentException;
use JmaXml\Earthquake\EarthquakeEvent;
use JmaXml\Earthquake\EarthquakeEvents;
use JmaXml\Earthquake\EventStage;
use JmaXml\Earthquake\SeismicIntensity;
use JmaXml\Earthquake\Tests\Support\Samples;
use JmaXml\Report;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class EarthquakeEventTest extends TestCase
{
    #[Test]
    public function separatesTwoEarthquakesWithDifferentEventIds(): void
    {
        $events = EarthquakeEvents::group([...Samples::earthquakeA(), ...Samples::earthquakeB()]);

        $this->assertCount(2, $events);

        $a = $events[0];
        $b = $events[1] ?? $this->fail('Two events expected');
        $this->assertSame('20100705065511', $a->eventId);
        $this->assertSame(EventStage::IntensityOnly, $a->stage);
        $this->assertNull($a->hypocenter);
        $this->assertSame(SeismicIntensity::Four, $a->maxIntensity);
        $this->assertSame(SeismicIntensity::Four, $a->areas['211']->maxIntensity);
        $this->assertSame(SeismicIntensity::Three, $a->areas['200']->maxIntensity);
        $this->assertSame([], $a->cities);
        $this->assertSame('2010-07-05T06:55:00+09:00', $a->detectedAt?->format(DATE_ATOM));

        $this->assertSame('20100705065610', $b->eventId);
        $this->assertSame(EventStage::WithHypocenterAndIntensity, $b->stage);
        $this->assertSame('岩手県沖', $b->hypocenter?->name);
        $this->assertSame('286', $b->hypocenter?->code);
        $this->assertSame(6.3, $b->hypocenter?->magnitude);
        $this->assertSame('Ｍ６．３', $b->hypocenter?->magnitudeDescription);
        $this->assertEqualsWithDelta(39.6, $b->hypocenter?->point?->latitude ?? 0.0, 1e-9);
        $this->assertSame('2010-07-05T06:55:00+09:00', $b->originTime?->format(DATE_ATOM));
        $this->assertSame(SeismicIntensity::Four, $b->maxIntensity);
        $this->assertSame(SeismicIntensity::Four, $b->cities['0321000']->maxIntensity);
        $this->assertSame(SeismicIntensity::Three, $b->cities['0320300']->maxIntensity);
        $this->assertSame([], $b->newerShindoSokuhoAreas);
    }

    #[Test]
    public function usesTheLatestReportOfEachKind(): void
    {
        $this->assertSame(SeismicIntensity::Three, EarthquakeEvent::fromReports(Samples::reports('32-35_08_01_100915_VXSE51.xml'))->maxIntensity);

        $event = EarthquakeEvent::fromReports(Samples::earthquakeA());

        $this->assertSame(SeismicIntensity::Four, $event->maxIntensity);
        $this->assertSame('2010-07-05T06:59:00+09:00', $event->lastReportedAt->format(DATE_ATOM));
    }

    #[Test]
    public function correctionReplacesTheLatestReport(): void
    {
        $xml = (string) file_get_contents(Fixtures::sample('32-35_08_08_240613_VXSE53.xml'));
        $xml = str_replace('<InfoType>発表</InfoType>', '<InfoType>訂正</InfoType>', $xml);
        $xml = str_replace(
            '<City><Name>陸前高田市</Name><Code>0321000</Code><MaxInt>4</MaxInt>',
            '<City><Name>陸前高田市</Name><Code>0321000</Code><MaxInt>5-</MaxInt>',
            $xml,
        );

        $event = EarthquakeEvent::fromReports([...Samples::reports('32-35_08_07_240613_VXSE53.xml'), Report::fromString($xml)]);

        $this->assertSame(SeismicIntensity::FiveLower, $event->cities['0321000']->maxIntensity);
        $this->assertSame(EventStage::WithHypocenterAndIntensity, $event->stage);
    }

    #[Test]
    public function orderOfReportsDoesNotMatter(): void
    {
        $reports = Samples::earthquakeB();

        $this->assertEquals(EarthquakeEvent::fromReports($reports), EarthquakeEvent::fromReports(array_reverse($reports)));
    }

    #[Test]
    public function partiallyCancelledEventUsesTheRemainingReport(): void
    {
        $event = EarthquakeEvent::fromReports(Samples::reports(...array_slice(Samples::cancelledSequence(), 0, 3)));

        $this->assertSame(EventStage::WithHypocenterAndIntensity, $event->stage);
        $this->assertSame('岩手県内陸南部', $event->hypocenter?->name);
    }

    #[Test]
    public function fullyCancelledEventMatchesNothing(): void
    {
        $event = EarthquakeEvent::fromReports(Samples::reports(...Samples::cancelledSequence()));

        $this->assertSame(EventStage::Cancelled, $event->stage);
        $this->assertTrue($event->isCancelled());
        $this->assertSame([], $event->areas);
        $this->assertSame([], $event->cities);
        $this->assertNull($event->maxIntensity);
    }

    #[Test]
    public function keepsDrillsApartFromRealEvents(): void
    {
        $events = EarthquakeEvents::group(Samples::reports('32-35_01_02_240613_VXSE52.xml', '32-35_01_03_240613_VXSE53.xml'));

        $this->assertCount(1, $events);
        $this->assertSame('訓練', $events[0]->status);
    }

    #[Test]
    public function readsTheStrongestShaking(): void
    {
        $event = EarthquakeEvent::fromReports(Samples::reports('32-39_11_01_120615_VXSE51.xml', '32-39_11_05_240613_VXSE53.xml'));

        $this->assertSame(SeismicIntensity::Seven, $event->maxIntensity);
    }

    #[Test]
    public function distantEarthquakeHasNoIntensity(): void
    {
        $event = EarthquakeEvent::fromReports(Samples::reports('32-39_05_01_100831_VXSE53.xml'));

        $this->assertSame(EventStage::WithHypocenterAndIntensity, $event->stage);
        $this->assertNull($event->maxIntensity);
        $this->assertSame([], $event->areas);
        $this->assertSame('南米西部', $event->hypocenter?->name);
    }

    #[Test]
    public function rejectsMixedOrForeignReports(): void
    {
        $tsunami = Samples::reports('32-39_11_02_250206_VTSE41.xml');
        $rejected = 0;

        foreach ([[], [...Samples::earthquakeA(), ...Samples::earthquakeB()], [...Samples::earthquakeA(), ...$tsunami]] as $reports) {
            try {
                EarthquakeEvent::fromReports($reports);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(3, $rejected);
        $this->assertCount(1, EarthquakeEvents::group([...Samples::earthquakeA(), ...$tsunami]));
    }
}
