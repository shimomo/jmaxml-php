<?php

declare(strict_types=1);

namespace JmaXml\Codes;

/**
 * 震度観測点（PointSeismicIntensity）の 1 件。コードが市町村コードと同じことがある。
 *
 * @author shimomo
 */
final readonly class IntensityStation
{
    public function __construct(
        public string $code,
        public string $name,
        public string $kana,
        public string $cityCode,
    ) {
        //
    }
}
