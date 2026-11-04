<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Codes;

use JmaXml\Codegen\Xlsx\XlsxReader;
use LogicException;

/**
 * The earthquake code tables of 地震火山関連コード表.xlsx, read and validated.
 *
 * Every table is keyed by code and sorted by code (as strings). PHP turns decimal-looking string keys such as
 * '100' into integers, so keys are array-key and must be cast back to string when read.
 *
 * Anything unexpected (a malformed code, a duplicate, a contradictory hierarchy, an unknown date format) stops
 * the generator with a LogicException instead of producing a wrong table.
 *
 * @author shimomo
 */
final readonly class EarthquakeCodeTables
{
    private const string PREFECTURE_CODE = '/^\d{2}$/';

    private const string AREA_CODE = '/^\d{3}$/';

    private const string CITY_CODE = '/^\d{7}$/';

    /**
     * @param array<array-key, list<string>> $prefectures code => [name]
     * @param array<array-key, array{string, string, string}> $areas code => [name, kana, prefecture code]
     * @param array<array-key, array{string, string, string}> $cities code => [name, kana, area code]
     * @param array<array-key, array{string, string, string}> $stations code => [name, kana, city code]
     * @param array<array-key, list<string>> $epicenters code => [name]
     * @param array<array-key, list<string>> $tsunamiAreas code => [name, kana, note]
     */
    public function __construct(
        public string $version,
        public array $prefectures,
        public array $areas,
        public array $cities,
        public array $stations,
        public array $epicenters,
        public array $tsunamiAreas,
    ) {
        //
    }

    public static function fromXlsx(string $path): self
    {
        $reader = XlsxReader::open($path);
        $codeName = ['A' => 'Code', 'B' => 'Name'];
        $codeNameKana = ['A' => 'Code', 'B' => 'Name', 'C' => 'ふりがな'];

        [$areas, $cities, $stations] = self::hierarchyFrom(self::dataRows($reader->rows('24'), '24', [
            ...$codeNameKana,
            'D' => 'Code', 'E' => 'Name', 'F' => 'ふりがな',
            'G' => 'Code', 'H' => 'Name', 'I' => 'ふりがな',
        ]));

        return self::fromParts(
            self::versionFrom($reader->rows('更新履歴')),
            self::simpleTable(self::dataRows($reader->rows('23'), '23', $codeName), '23', self::PREFECTURE_CODE, ['B']),
            $areas,
            $cities,
            $stations,
            self::simpleTable(self::dataRows($reader->rows('41'), '41', $codeName), '41', self::AREA_CODE, ['B']),
            self::simpleTable(self::dataRows($reader->rows('31'), '31', $codeNameKana), '31', self::AREA_CODE, ['B', 'C', 'D']),
        );
    }

    /**
     * Builds the tables after checking that they are complete and consistent with each other.
     *
     * @param array<array-key, list<string>> $prefectures
     * @param array<array-key, array{string, string, string}> $areas
     * @param array<array-key, array{string, string, string}> $cities
     * @param array<array-key, array{string, string, string}> $stations
     * @param array<array-key, list<string>> $epicenters
     * @param array<array-key, list<string>> $tsunamiAreas
     */
    public static function fromParts(
        string $version,
        array $prefectures,
        array $areas,
        array $cities,
        array $stations,
        array $epicenters,
        array $tsunamiAreas,
    ): self {
        foreach (['prefectures' => $prefectures, 'areas' => $areas, 'cities' => $cities, 'stations' => $stations, 'epicenters' => $epicenters, 'tsunami areas' => $tsunamiAreas] as $name => $table) {
            if ($table === []) {
                throw new LogicException('The ' . $name . ' table is empty');
            }
        }

        /** @var array<array-key, true> $prefecturesWithAreas */
        $prefecturesWithAreas = [];

        foreach ($areas as $code => [, , $prefecture]) {
            if (!isset($prefectures[$prefecture])) {
                throw new LogicException('Sheet 24: area ' . $code . ' belongs to unknown prefecture ' . $prefecture);
            }

            $prefecturesWithAreas[$prefecture] = true;
        }

        foreach (array_keys($prefectures) as $code) {
            if (!array_key_exists($code, $prefecturesWithAreas)) {
                throw new LogicException('Sheet 23: prefecture ' . (string) $code . ' has no area in sheet 24');
            }
        }

        return new self($version, self::sorted($prefectures), $areas, $cities, $stations, self::sorted($epicenters), self::sorted($tsunamiAreas));
    }

    /**
     * Reads a code table with one row per code: the code in column A and values in $columns (the first must not be empty).
     *
     * @param list<array<string, string>> $rows data rows
     * @param non-empty-string $pattern
     * @param non-empty-list<string> $columns
     *
     * @return array<array-key, list<string>> sorted by code
     */
    public static function simpleTable(array $rows, string $sheet, string $pattern, array $columns): array
    {
        $table = [];

        foreach ($rows as $row) {
            $code = self::code($row, 'A', $pattern, $sheet);
            self::assertNew($table, $code, $sheet, 'code');
            $values = [self::name($row, $columns[0], $sheet, $code)];

            foreach (array_slice($columns, 1) as $column) {
                $values[] = self::clean($row[$column] ?? '');
            }

            $table[$code] = $values;
        }

        return self::sorted($table);
    }

    /**
     * Returns the rows below the header row (the first row whose column A is "Code"). When $expectedHeader is given,
     * the header labels must match it, so that a change of layout stops the generator.
     *
     * @param list<array<string, string>> $rows
     * @param array<string, string> $expectedHeader column => label
     *
     * @return list<array<string, string>>
     */
    public static function dataRows(array $rows, string $sheet, array $expectedHeader = []): array
    {
        foreach ($rows as $index => $row) {
            if (self::clean($row['A'] ?? '') === 'Code') {
                foreach ($expectedHeader as $column => $label) {
                    if (self::clean($row[$column] ?? '') !== $label) {
                        throw new LogicException('Sheet ' . $sheet . ': expected header "' . $label . '" in column ' . $column . ', found "' . ($row[$column] ?? '') . '"');
                    }
                }

                return array_slice($rows, $index + 1);
            }
        }

        throw new LogicException('Sheet ' . $sheet . ' has no "Code" header row');
    }

    /**
     * The latest date in column B of the 更新履歴 sheet, e.g. "R8.10.19" → "2026-10-19".
     *
     * A cell may hold several dates, e.g. "H26.10.24\n(H26.11.21差し替え）"; all of them count.
     *
     * @param list<array<string, string>> $rows
     */
    public static function versionFrom(array $rows): string
    {
        $latest = null;

        foreach ($rows as $row) {
            $value = self::clean($row['B'] ?? '');

            if ($value === '' || $value === '年月日') {
                continue;
            }

            if (preg_match_all('/([RH])(\d{1,2})\.(\d{1,2})\.(\d{1,2})/', $value, $matches, PREG_SET_ORDER) < 1) {
                throw new LogicException('Unknown date format in 更新履歴: ' . $value);
            }

            foreach ($matches as [, $era, $eraYear, $month, $day]) {
                $year = ($era === 'R' ? 2018 : 1988) + (int) $eraYear;

                if (!checkdate((int) $month, (int) $day, $year)) {
                    throw new LogicException('Invalid date in 更新履歴: ' . $value);
                }

                $date = sprintf('%04d-%02d-%02d', $year, (int) $month, (int) $day);
                $latest = $latest === null || strcmp($date, $latest) > 0 ? $date : $latest;
            }
        }

        return $latest ?? throw new LogicException('No dates in 更新履歴');
    }

    /**
     * Splits the data rows of sheet 24 (area, city and station on every row) into three tables.
     *
     * @param list<array<string, string>> $rows
     *
     * @return array{
     *     0: array<array-key, array{string, string, string}>,
     *     1: array<array-key, array{string, string, string}>,
     *     2: array<array-key, array{string, string, string}>
     * }
     */
    public static function hierarchyFrom(array $rows): array
    {
        if ($rows === []) {
            throw new LogicException('Sheet 24 has no data rows');
        }

        $areas = [];
        $cities = [];
        $stations = [];

        foreach ($rows as $row) {
            $area = self::code($row, 'A', self::AREA_CODE, '24');
            $city = self::code($row, 'D', self::CITY_CODE, '24');
            $station = self::code($row, 'G', self::CITY_CODE, '24');
            $areaEntry = [self::name($row, 'B', '24', $area), self::clean($row['C'] ?? ''), substr($city, 0, 2)];
            $cityEntry = [self::name($row, 'E', '24', $city), self::clean($row['F'] ?? ''), $area];

            self::assertSame($areas, $area, $areaEntry, 'area');
            self::assertSame($cities, $city, $cityEntry, 'city');
            self::assertNew($stations, $station, '24', 'station code');

            $areas[$area] = $areaEntry;
            $cities[$city] = $cityEntry;
            $stations[$station] = [self::name($row, 'H', '24', $station), self::clean($row['I'] ?? ''), $city];
        }

        return [self::sorted($areas), self::sorted($cities), self::sorted($stations)];
    }

    /**
     * @param array<string, string> $row
     * @param non-empty-string $pattern
     */
    private static function code(array $row, string $column, string $pattern, string $sheet): string
    {
        $code = self::clean($row[$column] ?? '');

        if (preg_match($pattern, $code) !== 1) {
            throw new LogicException('Sheet ' . $sheet . ': malformed code "' . $code . '" in column ' . $column);
        }

        return $code;
    }

    /**
     * @param array<string, string> $row
     */
    private static function name(array $row, string $column, string $sheet, string $code): string
    {
        $name = self::clean($row[$column] ?? '');

        if ($name === '') {
            throw new LogicException('Sheet ' . $sheet . ': empty name for code ' . $code);
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $table
     */
    private static function assertNew(array $table, string $code, string $sheet, string $what): void
    {
        if (array_key_exists($code, $table)) {
            throw new LogicException('Sheet ' . $sheet . ': duplicate ' . $what . ' ' . $code);
        }
    }

    /**
     * @param array<array-key, array{string, string, string}> $table
     * @param array{string, string, string} $entry
     */
    private static function assertSame(array $table, string $code, array $entry, string $kind): void
    {
        if (array_key_exists($code, $table) && $table[$code] !== $entry) {
            throw new LogicException('Sheet 24: ' . $kind . ' ' . $code . ' is listed inconsistently (' . implode(' / ', $table[$code]) . ' vs ' . implode(' / ', $entry) . ')');
        }
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $table
     *
     * @return array<array-key, T>
     */
    private static function sorted(array $table): array
    {
        ksort($table, SORT_STRING);

        return $table;
    }

    private static function clean(string $value): string
    {
        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value);
    }
}
