<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Codes;

use JmaXml\Codegen\Generator;

/**
 * Renders the code tables as constant arrays in packages/codes/src/Data.
 *
 * @author shimomo
 */
final class CodesGenerator
{
    public function __construct(private readonly EarthquakeCodeTables $tables)
    {
        //
    }

    /**
     * @return array<string, string> path relative to packages/codes/src => file contents, sorted by path
     */
    public function generate(): array
    {
        $files = [
            'Data/CodeTableVersion.php' => self::file(
                'CodeTableVersion',
                ['地震火山関連コード表.xlsx の版（更新履歴の最新の日付）'],
                ['    public const string DATE = ' . self::quote($this->tables->version) . ';'],
            ),
            'Data/PrefectureData.php' => self::table('PrefectureData', '地震情報／都道府県等（AreaInformationPrefectureEarthquake）: code => [name]', $this->tables->prefectures),
            'Data/EarthquakeAreaData.php' => self::table('EarthquakeAreaData', '地震情報／細分区域（AreaForecastLocalE）: code => [name, kana, prefecture code]', $this->tables->areas),
            'Data/CityData.php' => self::table('CityData', '気象・地震・火山情報／市町村等（AreaInformationCity）: code => [name, kana, area code]', $this->tables->cities),
            'Data/IntensityStationData.php' => self::table('IntensityStationData', '震度観測点（PointSeismicIntensity）: code => [name, kana, city code]', $this->tables->stations),
            'Data/EpicenterAreaData.php' => self::table('EpicenterAreaData', '震央地名（AreaEpicenter）: code => [name]', $this->tables->epicenters),
            'Data/TsunamiAreaData.php' => self::table('TsunamiAreaData', '津波予報区（AreaTsunami）: code => [name, kana, note]', $this->tables->tsunamiAreas),
        ];

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<array-key, list<string>|array{string}|array{string, string, string}> $rows
     */
    private static function table(string $className, string $summary, array $rows): string
    {
        $lines = ['    public const array ROWS = ['];

        foreach ($rows as $code => $values) {
            $lines[] = '        ' . self::quote((string) $code) . ' => [' . implode(', ', array_map(self::quote(...), $values)) . '],';
        }

        $lines[] = '    ];';

        return self::file($className, [$summary], $lines);
    }

    /**
     * @param list<string> $summary
     * @param list<string> $body
     */
    private static function file(string $className, array $summary, array $body): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            Generator::MARKER,
            '',
            'namespace JmaXml\\Codes\\Data;',
            '',
            '/**',
            ...array_map(static fn(string $line): string => ' * ' . $line, $summary),
            ' *',
            ' * @internal',
            ' */',
            'final class ' . $className,
            '{',
            ...$body,
            '}',
        ];

        return implode("\n", $lines) . "\n";
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
