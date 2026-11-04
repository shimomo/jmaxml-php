<?php

declare(strict_types=1);

namespace JmaXml\Earthquake\Tests\Support;

use JmaXml\Report;
use JmaXml\Tests\Support\Fixtures;

/**
 * Reads official sample telegrams.
 *
 * @author shimomo
 */
final class Samples
{
    /**
     * @return list<Report>
     */
    public static function reports(string ...$fileNames): array
    {
        return array_values(array_map(static fn(string $fileName): Report => Report::fromFile(Fixtures::sample($fileName)), $fileNames));
    }

    /**
     * Reads a sample after applying string replacements (each search string must occur in the file).
     *
     * @param array<string, string> $replacements
     */
    public static function edited(string $fileName, array $replacements): Report
    {
        $xml = (string) file_get_contents(Fixtures::sample($fileName));

        foreach ($replacements as $search => $replace) {
            if (!str_contains($xml, $search)) {
                throw new \LogicException('Not in ' . $fileName . ': ' . $search);
            }

            $xml = str_replace($search, $replace, $xml);
        }

        return Report::fromString($xml);
    }

    /**
     * Earthquake A of the 32-35_08 sequence: three 震度速報 only.
     *
     * @return list<Report>
     */
    public static function earthquakeA(): array
    {
        return self::reports('32-35_08_01_100915_VXSE51.xml', '32-35_08_03_100915_VXSE51.xml', '32-35_08_05_100915_VXSE51.xml');
    }

    /**
     * Earthquake B of the 32-35_08 sequence: 震度速報, 震源に関する情報 and 震源・震度に関する情報.
     *
     * @return list<Report>
     */
    public static function earthquakeB(): array
    {
        return self::reports(
            '32-35_08_02_100915_VXSE51.xml',
            '32-35_08_04_100915_VXSE51.xml',
            '32-35_08_06_240613_VXSE52.xml',
            '32-35_08_07_240613_VXSE53.xml',
            '32-35_08_08_240613_VXSE53.xml',
        );
    }

    /**
     * The 32-35_06 sequence, in which both kinds of report end up cancelled.
     *
     * @return list<string>
     */
    public static function cancelledSequence(): array
    {
        return [
            '32-35_06_01_240613_VXSE52.xml',
            '32-35_06_02_100915_VXSE52.xml',
            '32-35_06_03_240613_VXSE53.xml',
            '32-35_06_04_240613_VXSE53.xml',
            '32-35_06_05_240613_VXSE53.xml',
            '32-35_06_06_100915_VXSE53.xml',
        ];
    }
}
