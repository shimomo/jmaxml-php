<?php

declare(strict_types=1);

namespace JmaXml\Geo;

use InvalidArgumentException;

/**
 * Decodes the ISO 6709 strings used by Coordinate, Line and Polygon elements.
 *
 * The notation (degrees, degree-minutes or degree-minute-seconds) is taken from the
 * element's type attribute when it says so, because some telegrams omit the leading
 * zeros that ISO 6709 requires (spec 1.3.5). Otherwise it is inferred from the number
 * of integer digits in the latitude.
 *
 * @author shimomo
 */
final class Iso6709
{
    private const string SEGMENT_PATTERN = '/^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)?$/';

    /**
     * @return list<Point>
     */
    public static function parse(string $value, ?string $type = null): array
    {
        $points = [];

        foreach (explode('/', $value) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if (preg_match(self::SEGMENT_PATTERN, $segment, $matches) !== 1) {
                throw new InvalidArgumentException('Invalid ISO 6709 segment "' . $segment . '"');
            }

            $notation = self::notation($type, $matches[1]);

            $latitude = self::toDegrees($matches[1], $notation);
            $longitude = self::toDegrees($matches[2], $notation);

            if (abs($latitude) > 90.0 || abs($longitude) > 180.0) {
                throw new InvalidArgumentException('ISO 6709 point out of range "' . $segment . '"');
            }

            $points[] = new Point(
                $latitude,
                $longitude,
                isset($matches[3]) ? (float) $matches[3] : null,
            );
        }

        return $points;
    }

    /**
     * @return 'D'|'DM'|'DMS'
     */
    private static function notation(?string $type, string $latitude): string
    {
        if ($type !== null) {
            if (str_contains($type, '度分秒')) {
                return 'DMS';
            }

            if (str_contains($type, '度分')) {
                return 'DM';
            }

            if (str_contains($type, '度')) {
                return 'D';
            }
        }

        $digits = strlen(explode('.', ltrim($latitude, '+-'))[0]);

        return match (true) {
            $digits <= 2 => 'D',
            $digits <= 4 => 'DM',
            default => 'DMS',
        };
    }

    /**
     * @param 'D'|'DM'|'DMS' $notation
     */
    private static function toDegrees(string $component, string $notation): float
    {
        $sign = str_starts_with($component, '-') ? -1.0 : 1.0;
        $value = abs((float) $component);

        $degrees = match ($notation) {
            'D' => $value,
            'DM' => self::fromDegreeMinutes($value),
            'DMS' => self::fromDegreeMinuteSeconds($value),
        };

        return $sign * $degrees;
    }

    private static function fromDegreeMinutes(float $value): float
    {
        $degrees = floor($value / 100.0);
        $minutes = $value - $degrees * 100.0;

        if ($minutes >= 60.0) {
            throw new InvalidArgumentException('ISO 6709 minutes out of range in "' . (string) $value . '"');
        }

        return $degrees + $minutes / 60.0;
    }

    private static function fromDegreeMinuteSeconds(float $value): float
    {
        $degrees = floor($value / 10000.0);
        $rest = $value - $degrees * 10000.0;
        $minutes = floor($rest / 100.0);
        $seconds = $rest - $minutes * 100.0;

        if ($minutes >= 60.0 || $seconds >= 60.0) {
            throw new InvalidArgumentException('ISO 6709 minutes or seconds out of range in "' . (string) $value . '"');
        }

        return $degrees + $minutes / 60.0 + $seconds / 3600.0;
    }
}
