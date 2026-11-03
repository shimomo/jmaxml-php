<?php

declare(strict_types=1);

namespace JmaXml\Codes;

use JmaXml\Codes\Data\CityData;
use JmaXml\Codes\Data\CodeTableVersion;
use JmaXml\Codes\Data\EarthquakeAreaData;
use JmaXml\Codes\Data\EpicenterAreaData;
use JmaXml\Codes\Data\IntensityStationData;
use JmaXml\Codes\Data\PrefectureData;
use JmaXml\Codes\Data\TsunamiAreaData;

/**
 * Looks up the earthquake code tables of the JMA XML format.
 *
 * Codes are matched exactly as strings (no trimming, no zero padding). Codes that are not in the bundled
 * tables, such as those of merged municipalities in old telegrams, give null or an empty list.
 * Lists are in code order.
 *
 * @author shimomo
 */
final class EarthquakeCodes
{
    /**
     * The date of the bundled code tables (the latest entry of their update history), e.g. "2026-10-19".
     */
    public static function version(): string
    {
        return CodeTableVersion::DATE;
    }

    public static function prefecture(string $code): ?Prefecture
    {
        $row = self::find(PrefectureData::ROWS, $code);

        return $row === null ? null : new Prefecture($code, $row[0]);
    }

    /**
     * 地震情報／細分区域.
     */
    public static function area(string $code): ?EarthquakeArea
    {
        $row = self::find(EarthquakeAreaData::ROWS, $code);

        return $row === null ? null : new EarthquakeArea($code, $row[0], $row[1], $row[2]);
    }

    public static function city(string $code): ?City
    {
        $row = self::find(CityData::ROWS, $code);

        return $row === null ? null : new City($code, $row[0], $row[1], $row[2]);
    }

    public static function station(string $code): ?IntensityStation
    {
        $row = self::find(IntensityStationData::ROWS, $code);

        return $row === null ? null : new IntensityStation($code, $row[0], $row[1], $row[2]);
    }

    public static function epicenter(string $code): ?EpicenterArea
    {
        $row = self::find(EpicenterAreaData::ROWS, $code);

        return $row === null ? null : new EpicenterArea($code, $row[0]);
    }

    public static function tsunamiArea(string $code): ?TsunamiArea
    {
        $row = self::find(TsunamiAreaData::ROWS, $code);

        return $row === null ? null : new TsunamiArea($code, $row[0], $row[1], $row[2]);
    }

    /**
     * @return list<Prefecture>
     */
    public static function prefectures(): array
    {
        $prefectures = [];

        foreach (PrefectureData::ROWS as $code => $row) {
            $prefectures[] = new Prefecture((string) $code, $row[0]);
        }

        return $prefectures;
    }

    /**
     * @return list<City>
     */
    public static function cities(): array
    {
        $cities = [];

        foreach (CityData::ROWS as $code => $row) {
            $cities[] = new City((string) $code, $row[0], $row[1], $row[2]);
        }

        return $cities;
    }

    /**
     * @return list<EarthquakeArea>
     */
    public static function areasInPrefecture(string $prefectureCode): array
    {
        $areas = [];

        foreach (EarthquakeAreaData::ROWS as $code => $row) {
            if ($row[2] === $prefectureCode) {
                $areas[] = new EarthquakeArea((string) $code, $row[0], $row[1], $row[2]);
            }
        }

        return $areas;
    }

    /**
     * The cities of an area. Use it to match a registered city against 震度速報 (VXSE51), which reports by area.
     *
     * @return list<City>
     */
    public static function citiesInArea(string $areaCode): array
    {
        $cities = [];

        foreach (CityData::ROWS as $code => $row) {
            if ($row[2] === $areaCode) {
                $cities[] = new City((string) $code, $row[0], $row[1], $row[2]);
            }
        }

        return $cities;
    }

    /**
     * @return list<IntensityStation>
     */
    public static function stationsInCity(string $cityCode): array
    {
        $stations = [];

        foreach (IntensityStationData::ROWS as $code => $row) {
            if ($row[2] === $cityCode) {
                $stations[] = new IntensityStation((string) $code, $row[0], $row[1], $row[2]);
            }
        }

        return $stations;
    }

    /**
     * PHP stores decimal-looking codes such as '100' as integer keys; a string lookup still finds them.
     *
     * @template T of array
     *
     * @param array<array-key, T> $rows
     *
     * @return T|null
     */
    private static function find(array $rows, string $code): ?array
    {
        return $rows[$code] ?? null;
    }
}
