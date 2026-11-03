<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests\Codes;

use JmaXml\Codegen\Codes\EarthquakeCodeTables;
use JmaXml\Tests\Support\Fixtures;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class EarthquakeCodeTablesTest extends TestCase
{
    #[Test]
    public function readsOfficialTables(): void
    {
        $tables = EarthquakeCodeTables::fromXlsx(Fixtures::earthquakeCodeTablePath());

        $this->assertSame('2026-10-19', $tables->version);
        $this->assertCount(47, $tables->prefectures);
        $this->assertCount(188, $tables->areas);
        $this->assertCount(1894, $tables->cities);
        $this->assertCount(4362, $tables->stations);
        $this->assertCount(343, $tables->epicenters);
        $this->assertCount(98, $tables->tsunamiAreas);

        $this->assertSame(['東京都'], $tables->prefectures['13']);
        $this->assertSame(['東京都２３区', 'とうきょうとにじゅうさんく', '13'], $tables->areas['350']);
        $this->assertSame(['東京千代田区', 'とうきょうちよだく', '350'], $tables->cities['1310100']);
        $this->assertSame(['東京千代田区大手町', 'とうきょうちよだくおおてまち', '1310100'], $tables->stations['1310100']);
        $this->assertSame('石狩市', $tables->cities['0123500'][0]);
        $this->assertSame('石狩市花川', $tables->stations['0123500'][0]);
        $this->assertSame(['駿河湾'], $tables->epicenters['485']);
        $this->assertSame(['沖縄県地方', 'おきなわけんちほう', '領域表現（構成する津波予報区800,801,802）'], $tables->tsunamiAreas['891']);

        $codes = array_map('strval', array_keys($tables->cities));
        $sorted = $codes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $codes);
    }

    #[Test]
    public function findsDataRowsBelowTheCodeHeader(): void
    {
        $this->assertSame(
            [['A' => '01', 'B' => '北海道']],
            EarthquakeCodeTables::dataRows([['A' => 'タイトル'], ['A' => '追加の行'], ['A' => 'Code', 'B' => 'Name'], ['A' => '01', 'B' => '北海道']], '23'),
        );

        $this->expectException(LogicException::class);

        EarthquakeCodeTables::dataRows([['A' => 'タイトル']], '23');
    }

    #[Test]
    public function convertsJapaneseEraDates(): void
    {
        $this->assertSame(
            '2026-10-19',
            EarthquakeCodeTables::versionFrom([['B' => '年月日'], ['B' => 'H22.08.31'], ['B' => 'R8.10.19'], ['B' => 'R8.6.23'], ['C' => 'シート']]),
        );

        // A cell can carry a note with a replacement date; the later date counts.
        $this->assertSame('2014-11-21', EarthquakeCodeTables::versionFrom([['B' => "H26.10.24\n(H26.11.21差し替え）"]]));
        $this->assertSame('2015-10-14', EarthquakeCodeTables::versionFrom([['B' => "H26.10.24\n(H26.11.21差し替え）"], ['B' => 'H27.10.14  ']]));

        $this->expectException(LogicException::class);

        EarthquakeCodeTables::versionFrom([['B' => '2026/10/19']]);
    }

    #[Test]
    public function rejectsMalformedCodes(): void
    {
        $this->expectException(LogicException::class);

        EarthquakeCodeTables::hierarchyFrom([self::row(city: '123500')]);
    }

    #[Test]
    public function rejectsCityInTwoAreas(): void
    {
        $this->expectException(LogicException::class);

        EarthquakeCodeTables::hierarchyFrom([self::row(), self::row(area: '101', station: '0123501')]);
    }

    #[Test]
    public function rejectsAreaSpanningTwoPrefectures(): void
    {
        $this->expectException(LogicException::class);

        EarthquakeCodeTables::hierarchyFrom([self::row(), self::row(city: '0213500', station: '0213500')]);
    }

    #[Test]
    public function rejectsAreaWithTwoNames(): void
    {
        $this->expectException(LogicException::class);

        EarthquakeCodeTables::hierarchyFrom([self::row(), self::row(areaName: '別の名前', city: '0130300', station: '0130320')]);
    }

    #[Test]
    public function trimsCellValues(): void
    {
        [$areas, $cities] = EarthquakeCodeTables::hierarchyFrom([self::row(areaName: "\u{3000}石狩地方北部 ", cityName: ' 石狩市')]);

        $this->assertSame('石狩地方北部', $areas['100'][0]);
        $this->assertSame('石狩市', $cities['0123500'][0]);
    }

    /**
     * One data row of sheet 24.
     *
     * @return array<string, string>
     */
    private static function row(
        string $area = '100',
        string $areaName = '石狩地方北部',
        string $city = '0123500',
        string $cityName = '石狩市',
        string $station = '0123500',
    ): array {
        return [
            'A' => $area,
            'B' => $areaName,
            'C' => 'いしかりちほうほくぶ',
            'D' => $city,
            'E' => $cityName,
            'F' => 'いしかりし',
            'G' => $station,
            'H' => '石狩市花川',
            'I' => 'いしかりしはなかわ',
        ];
    }
}
