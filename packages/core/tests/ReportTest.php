<?php

declare(strict_types=1);

namespace JmaXml\Tests;

use JmaXml\Exception\ParseException;
use JmaXml\Geo\Point;
use JmaXml\Meteorology;
use JmaXml\Report;
use JmaXml\Seismology;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Representative values read from the official sample telegrams.
 *
 * @author shimomo
 */
final class ReportTest extends TestCase
{
    #[Test]
    public function readsEarthquakeHypocenterAndIntensity(): void
    {
        $report = Report::fromFile(Fixtures::sample('32-35_01_03_240613_VXSE53.xml'));

        $this->assertSame('震源・震度に関する情報', $report->control->title);
        $this->assertSame('訓練', $report->control->status);
        $this->assertSame('気象庁本庁', $report->control->editorialOffice);
        $this->assertSame('2009-10-01T04:50:01+00:00', $report->control->dateTime->format(DATE_ATOM));
        $this->assertSame('震源・震度情報', $report->head->title);
        $this->assertSame('20091001134500', $report->head->eventID);
        $this->assertSame('1', $report->head->serial);
        $this->assertSame('1.0_0', $report->head->infoKindVersion);
        $this->assertSame('2009-10-01T13:50:00+09:00', $report->head->reportDateTime->format(DATE_ATOM));

        $body = $report->body;
        $this->assertInstanceOf(Seismology\Body::class, $body);

        $hypocenter = $body->earthquake[0]->hypocenter;
        $this->assertNotNull($hypocenter);
        $this->assertSame('駿河湾', $hypocenter->area->name);
        $this->assertSame('485', $hypocenter->area->code->value);
        $this->assertSame('震央地名', $hypocenter->area->code->type);

        $magnitude = $body->earthquake[0]->magnitude[0];
        $this->assertSame(5.9, $magnitude->value);
        $this->assertSame('Mj', $magnitude->type);
        $this->assertSame('Ｍ５．９', $magnitude->description);

        $coordinate = $hypocenter->area->coordinate[0];
        $this->assertSame('+34.8+138.5-10000/', $coordinate->value);
        $this->assertEquals([new Point(34.8, 138.5, -10000.0)], $coordinate->points());

        $observation = $body->intensity?->observation;
        $this->assertNotNull($observation);
        $this->assertSame('5-', $observation->maxInt);

        $atami = [];

        foreach ($observation->pref as $pref) {
            foreach ($pref->area as $area) {
                foreach ($area->city as $city) {
                    if ($city->name === '熱海市') {
                        $atami[] = $city->maxInt;
                    }
                }
            }
        }

        // MaxInt is xs:string, so the trailing space in the telegram is kept as is.
        $this->assertSame(['4 '], $atami);
    }

    #[Test]
    public function readsShindoSokuhoWithEmptySerial(): void
    {
        $report = Report::fromFile(Fixtures::sample('32-35_08_05_100915_VXSE51.xml'));

        $this->assertSame('', $report->head->serial);
        $this->assertSame('20100705065511', $report->head->eventID);

        $body = $report->body;
        $this->assertInstanceOf(Seismology\Body::class, $body);

        $observation = $body->intensity?->observation;
        $this->assertNotNull($observation);
        $this->assertSame('4', $observation->maxInt);
        $this->assertSame('岩手県', $observation->pref[0]->name);
        $this->assertSame('03', $observation->pref[0]->code);
    }

    #[Test]
    public function readsCancellation(): void
    {
        $report = Report::fromFile(Fixtures::sample('32-35_06_06_100915_VXSE53.xml'));

        $this->assertSame('取消', $report->head->infoType);

        $body = $report->body;
        $this->assertInstanceOf(Seismology\Body::class, $body);
        $this->assertSame('先ほどの、震源・震度情報を取り消します。', $body->text);
        $this->assertSame([], $body->earthquake);
    }

    #[Test]
    public function nanMagnitudeBecomesNullAndKeepsDescription(): void
    {
        $body = Report::fromFile(Fixtures::sample('32-39_11_02_250206_VTSE41.xml'))->body;
        $this->assertInstanceOf(Seismology\Body::class, $body);

        $magnitude = $body->earthquake[0]->magnitude[0];
        $this->assertNull($magnitude->value);
        $this->assertSame('不明', $magnitude->condition);
        $this->assertSame('Ｍ８を超える巨大地震', $magnitude->description);
    }

    #[Test]
    public function readsNewWarningAndForecastTelegrams(): void
    {
        $warning = Report::fromFile(Fixtures::sample('15_17_01_251222_VPWW55.xml'));
        $this->assertSame('気象警報・注意報（Ｒ０６）（大雨）', $warning->control->title);
        $this->assertSame('1.5_0', $warning->head->infoKindVersion);
        $this->assertInstanceOf(Meteorology\Body::class, $warning->body);
        $this->assertCount(4, $warning->body->warning);

        $forecast = Report::fromFile(Fixtures::sample('24_11_01_190925_VPFD51.xml'));
        $duration = $forecast->head->targetDuration;
        $this->assertNotNull($duration);
        $this->assertSame(1, $duration->d);
        $this->assertSame(19, $duration->h);
        $this->assertInstanceOf(Meteorology\Body::class, $forecast->body);
        $this->assertCount(4, $forecast->body->meteorologicalInfos);
    }

    #[Test]
    public function ignoresUnknownElementsAndAttributes(): void
    {
        $xml = str_replace(
            ['<Title>震度速報</Title>', '<InfoType>'],
            ['<Title future="1">震度速報</Title>', '<FutureElement>x</FutureElement><InfoType>'],
            self::shindoSokuho(),
        );

        $this->assertSame('震度速報', Report::fromString($xml)->head->title);
    }

    #[Test]
    public function nilTargetDateTimeBecomesNull(): void
    {
        $xml = (string) preg_replace(
            '#<TargetDateTime>[^<]*</TargetDateTime>#',
            '<TargetDateTime xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/>',
            self::shindoSokuho(),
        );

        $this->assertNull(Report::fromString($xml)->head->targetDateTime);
    }

    #[Test]
    public function missingRequiredElementReportsPath(): void
    {
        $xml = (string) preg_replace('#<InfoType>[^<]*</InfoType>#', '', self::shindoSokuho());

        $this->expectExceptionObject(new ParseException('Missing required element', '/Report/Head/InfoType'));

        Report::fromString($xml);
    }

    #[Test]
    public function unknownBodyNamespaceIsRejected(): void
    {
        $xml = str_replace(
            '<Body xmlns="http://xml.kishou.go.jp/jmaxml1/body/seismology1/"',
            '<Body xmlns="urn:unknown"',
            self::shindoSokuho(),
        );

        try {
            Report::fromString($xml);
            $this->fail('ParseException was not thrown');
        } catch (ParseException $exception) {
            $this->assertSame('/Report/Body', $exception->path);
        }
    }

    private static function shindoSokuho(): string
    {
        return (string) file_get_contents(Fixtures::sample('32-35_08_05_100915_VXSE51.xml'));
    }
}
