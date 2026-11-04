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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $drills = Samples::reports('32-35_01_02_240613_VXSE52.xml', '32-35_01_03_240613_VXSE53.xml');
        $events = EarthquakeEvents::group($drills, ['通常', '訓練']);

        $this->assertCount(1, $events);
        $this->assertSame('訓練', $events[0]->status);
        $this->assertSame('訓練', EarthquakeEvent::fromReports($drills)->status);
    }

    #[Test]
    public function groupLeavesDrillsOutUnlessAsked(): void
    {
        $drill = Report::fromString(str_replace(
            '<Status>通常</Status>',
            '<Status>訓練</Status>',
            (string) file_get_contents(Fixtures::sample('32-35_08_08_240613_VXSE53.xml')),
        ));
        $reports = [...Samples::earthquakeB(), $drill];

        $this->assertSame(['通常'], array_map(static fn(EarthquakeEvent $event): string => $event->status, EarthquakeEvents::group($reports)));
        $this->assertCount(2, EarthquakeEvents::group($reports, ['通常', '訓練']));
    }

    #[Test]
    public function acceptsReportsThatAreNotAList(): void
    {
        $reports = Samples::earthquakeA();
        $filtered = array_filter($reports, static fn(Report $report): bool => $report !== $reports[0]);

        $this->assertSame(SeismicIntensity::Four, EarthquakeEvent::fromReports($filtered)->maxIntensity);
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

    /**
     * @return iterable<string, array{list<Report>}>
     */
    public static function badInputs(): iterable
    {
        yield 'empty' => [[]];
        yield 'two earthquakes' => [[...Samples::earthquakeA(), ...Samples::earthquakeB()]];
        yield 'tsunami telegram' => [[...Samples::earthquakeA(), ...Samples::reports('32-39_11_02_250206_VTSE41.xml')]];
    }

    /**
     * @param list<Report> $reports
     */
    #[Test]
    #[DataProvider('badInputs')]
    public function rejectsEachKindOfBadInput(array $reports): void
    {
        $this->expectException(InvalidArgumentException::class);

        EarthquakeEvent::fromReports($reports);
    }

    #[Test]
    public function groupIgnoresForeignTelegrams(): void
    {
        $this->assertCount(1, EarthquakeEvents::group([...Samples::earthquakeA(), ...Samples::reports('32-39_11_02_250206_VTSE41.xml')]));
    }

    #[Test]
    public function looksUpRegionsByStringCode(): void
    {
        $event = EarthquakeEvent::fromReports(Samples::reports('32-39_11_01_120615_VXSE51.xml', '32-39_11_05_240613_VXSE53.xml'));

        $this->assertSame(SeismicIntensity::FiveUpper, $event->city('1310100')?->maxIntensity);
        $this->assertSame(SeismicIntensity::FiveUpper, $event->area('350')?->maxIntensity);
        $this->assertSame('東京都', $event->prefecture('13')?->name);
        $this->assertSame(SeismicIntensity::FiveUpper, $event->station('1310100')?->maxIntensity);
        $this->assertNull($event->city('9999999'));
    }

    #[Test]
    public function lookupsTakeTheStrongerReadingOfANewerShindoSokuho(): void
    {
        $detailed = Samples::reports('32-35_04_02_100831_VXSE51.xml', '32-35_04_03_240613_VXSE52.xml', '32-35_04_04_240613_VXSE53.xml');
        $newer = Samples::edited('32-35_04_05_100831_VXSE51.xml', [
            '<Code>771</Code><MaxInt>4</MaxInt>' => '<Code>771</Code><MaxInt>3</MaxInt>',
            '<Code>770</Code><MaxInt>3</MaxInt>' => '<Code>770</Code><MaxInt>3</MaxInt><Condition>震度５弱以上未入電</Condition>',
        ]);
        $event = EarthquakeEvent::fromReports([...$detailed, $newer]);

        // 宮崎県南部平野部 is 3 in the detailed report and 4 in the newer 震度速報.
        $this->assertSame(SeismicIntensity::Four, $event->area('762')?->maxIntensity);
        $this->assertSame('宮崎県南部平野部', $event->area('762')?->name);
        $this->assertSame(SeismicIntensity::Four, $event->prefecture('45')?->maxIntensity);
        // A weaker newer reading does not lower the detailed one, and a region stays open if either is not yet received.
        $this->assertSame(SeismicIntensity::Four, $event->area('771')?->maxIntensity);
        $this->assertTrue($event->area('770')?->notYetReceived);
        $this->assertTrue($event->prefecture('46')?->notYetReceived);
        $this->assertFalse($event->area('762')?->notYetReceived);
    }

    #[Test]
    public function exposesPartialCancellation(): void
    {
        $sequence = Samples::cancelledSequence();

        $this->assertSame(['震源に関する情報'], EarthquakeEvent::fromReports(Samples::reports(...array_slice($sequence, 0, 3)))->cancelledKinds);
        $this->assertSame(['震源に関する情報', '震源・震度に関する情報'], EarthquakeEvent::fromReports(Samples::reports(...$sequence))->cancelledKinds);
        $this->assertSame([], EarthquakeEvent::fromReports(Samples::earthquakeB())->cancelledKinds);
    }

    #[Test]
    public function mayBeAtLeastCountsNotYetReceivedRegions(): void
    {
        $event = EarthquakeEvent::fromReports(Samples::reports('32-35_06_01_240613_VXSE52.xml', '32-35_06_03_240613_VXSE53.xml', '32-35_06_04_240613_VXSE53.xml'));

        $this->assertSame(SeismicIntensity::SixUpper, $event->maxIntensity);
        $this->assertTrue($event->mayBeAtLeast(SeismicIntensity::Seven));
        $this->assertFalse(EarthquakeEvent::fromReports(Samples::earthquakeA())->mayBeAtLeast(SeismicIntensity::FiveLower));
        $this->assertTrue(EarthquakeEvent::fromReports(Samples::earthquakeA())->mayBeAtLeast(SeismicIntensity::Four));
    }

    #[Test]
    public function sameEventIdWithDifferentStatusesStaysApart(): void
    {
        $drill = Samples::edited('32-35_08_08_240613_VXSE53.xml', ['<Status>通常</Status>' => '<Status>訓練</Status>']);

        $this->assertCount(2, EarthquakeEvents::group([...Samples::earthquakeB(), $drill], ['通常', '訓練']));
    }

    #[Test]
    public function lonelyCancellationIsCancelled(): void
    {
        $this->assertTrue(EarthquakeEvent::fromReports(Samples::reports('32-35_10_01_220510_VXSE51.xml'))->isCancelled());
    }

    #[Test]
    public function sameReportTimeFallsBackToControlDateTime(): void
    {
        $files = ['32-35_07_01_100915_VXSE51.xml', '32-35_07_02_100915_VXSE51.xml', '32-35_07_03_100915_VXSE51.xml'];
        $expected = EarthquakeEvent::fromReports(Samples::reports('32-35_07_03_100915_VXSE51.xml'))->areas;

        foreach ([[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]] as $order) {
            $reports = Samples::reports(...array_map(static fn(int $i): string => $files[$i], $order));

            $this->assertEquals($expected, EarthquakeEvent::fromReports($reports)->areas);
        }
    }
}
