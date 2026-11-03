<?php

declare(strict_types=1);

namespace JmaXml\Codes\Tests;

use JmaXml\Codes\City;
use JmaXml\Codes\EarthquakeArea;
use JmaXml\Codes\EarthquakeCodes;
use JmaXml\Codes\EpicenterArea;
use JmaXml\Codes\IntensityStation;
use JmaXml\Codes\Prefecture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class EarthquakeCodesTest extends TestCase
{
    #[Test]
    public function looksUpTheTokyoHierarchy(): void
    {
        $city = EarthquakeCodes::city('1310100');

        $this->assertEquals(new City('1310100', '東京千代田区', 'とうきょうちよだく', '350'), $city);
        $this->assertEquals(new EarthquakeArea('350', '東京都２３区', 'とうきょうとにじゅうさんく', '13'), EarthquakeCodes::area($city?->areaCode ?? ''));
        $this->assertEquals(new Prefecture('13', '東京都'), EarthquakeCodes::prefecture('13'));
    }

    #[Test]
    public function keepsStationAndCityCodesApart(): void
    {
        $this->assertSame('石狩市', EarthquakeCodes::city('0123500')?->name);
        $this->assertEquals(
            new IntensityStation('0123500', '石狩市花川', 'いしかりしはなかわ', '0123500'),
            EarthquakeCodes::station('0123500'),
        );
    }

    #[Test]
    public function listsChildrenInCodeOrder(): void
    {
        $this->assertSame(
            ['210', '211', '212', '213'],
            array_map(static fn(EarthquakeArea $area): string => $area->code, EarthquakeCodes::areasInPrefecture('03')),
        );
        $this->assertSame(
            ['0123500', '0130300', '0130400'],
            array_map(static fn(City $city): string => $city->code, EarthquakeCodes::citiesInArea('100')),
        );
        $this->assertCount(47, EarthquakeCodes::prefectures());
        $this->assertCount(1894, EarthquakeCodes::cities());
        $this->assertSame('01', EarthquakeCodes::prefectures()[0]->code);

        $stations = EarthquakeCodes::stationsInCity('0123500');
        $this->assertSame('0123500', $stations[0]->code);
        $this->assertSame(['0123500'], array_values(array_unique(array_map(static fn(IntensityStation $station): string => $station->cityCode, $stations))));
    }

    #[Test]
    public function readsEpicentersAndTsunamiAreas(): void
    {
        $this->assertEquals(new EpicenterArea('485', '駿河湾'), EarthquakeCodes::epicenter('485'));
        $this->assertSame('領域表現（構成する津波予報区800,801,802）', EarthquakeCodes::tsunamiArea('891')?->note);
    }

    #[Test]
    public function exposesTheCodeTableVersion(): void
    {
        $this->assertSame('2026-10-19', EarthquakeCodes::version());
    }

    #[Test]
    public function parentCityAndRetiredWardCodesAreNotInTheEarthquakeTable(): void
    {
        // The README warns about these: they must be caught when a registration is stored.
        $this->assertNull(EarthquakeCodes::city('0110000'));
        $this->assertSame('札幌中央区', EarthquakeCodes::city('0110100')?->name);
        $this->assertNull(EarthquakeCodes::city('2213100'));
        $this->assertSame('浜松中央区', EarthquakeCodes::city('2213800')?->name);
    }

    #[Test]
    public function unknownCodesReturnNothing(): void
    {
        $this->assertNull(EarthquakeCodes::city('9999999'));
        $this->assertNull(EarthquakeCodes::area('999'));
        $this->assertNull(EarthquakeCodes::prefecture('99'));
        $this->assertNull(EarthquakeCodes::station('0000000'));
        $this->assertNull(EarthquakeCodes::epicenter('000'));
        $this->assertNull(EarthquakeCodes::tsunamiArea('000'));
        $this->assertNull(EarthquakeCodes::city(' 1310100'));
        $this->assertNull(EarthquakeCodes::city('1310100 '));
        $this->assertSame([], EarthquakeCodes::citiesInArea('999'));
        $this->assertSame([], EarthquakeCodes::areasInPrefecture('99'));
        $this->assertSame([], EarthquakeCodes::stationsInCity('9999999'));
    }
}
