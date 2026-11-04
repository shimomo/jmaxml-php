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
    public function checksTheHeaderLabels(): void
    {
        $expected = ['A' => 'Code', 'B' => 'Name', 'C' => 'ふりがな'];

        $this->assertSame(
            [['A' => '100', 'B' => '石狩地方北部', 'C' => 'いしかりちほうほくぶ']],
            EarthquakeCodeTables::dataRows([['A' => 'Code', 'B' => 'Name', 'C' => 'ふりがな'], ['A' => '100', 'B' => '石狩地方北部', 'C' => 'いしかりちほうほくぶ']], '24', $expected),
        );

        $this->expectException(LogicException::class);

        EarthquakeCodeTables::dataRows([['A' => 'Code', 'B' => 'ふりがな', 'C' => 'Name']], '24', $expected);
    }

    #[Test]
    public function simpleTablesReadAndValidateRows(): void
    {
        $this->assertSame(
            ['891' => ['沖縄県地方', 'おきなわけんちほう', '領域表現']],
            EarthquakeCodeTables::simpleTable([['A' => '891', 'B' => '沖縄県地方', 'C' => 'おきなわけんちほう', 'D' => '領域表現']], '31', '/^\d{3}$/', ['B', 'C', 'D']),
        );

        $rejected = 0;

        foreach ([[['A' => '11', 'B' => '北海道地方']], [['A' => '011', 'B' => 'x'], ['A' => '011', 'B' => 'y']], [['A' => '011']]] as $rows) {
            try {
                EarthquakeCodeTables::simpleTable($rows, '41', '/^\d{3}$/', ['B']);
            } catch (LogicException) {
                $rejected++;
            }
        }

        $this->assertSame(3, $rejected);
    }

    #[Test]
    public function requiresCompleteTables(): void
    {
        $rejected = 0;

        try {
            EarthquakeCodeTables::hierarchyFrom([]);
        } catch (LogicException) {
            $rejected++;
        }

        [$areas, $cities, $stations] = EarthquakeCodeTables::hierarchyFrom([self::row()]);

        try {
            EarthquakeCodeTables::fromParts('2026-10-19', ['01' => ['北海道'], '02' => ['青森県']], $areas, $cities, $stations, ['011' => ['北海道地方']], ['100' => ['北海道太平洋沿岸東部', '', '']]);
        } catch (LogicException $exception) {
            $this->assertStringContainsString('02', $exception->getMessage());
            $rejected++;
        }

        $this->assertSame(2, $rejected);
        $this->assertSame('2026-10-19', EarthquakeCodeTables::fromParts('2026-10-19', ['01' => ['北海道']], $areas, $cities, $stations, ['011' => ['北海道地方']], ['100' => ['北海道太平洋沿岸東部', '', '']])->version);
    }

    #[Test]
    public function duplicateStationMessageNamesTheStation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/station/');

        EarthquakeCodeTables::hierarchyFrom([self::row(), self::row()]);
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
