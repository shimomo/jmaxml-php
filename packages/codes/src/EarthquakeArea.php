<?php

declare(strict_types=1);

namespace JmaXml\Codes;

/**
 * 地震情報／細分区域（AreaForecastLocalE）の 1 件。震度速報（VXSE51）が震度を伝える単位。
 *
 * @author shimomo
 */
final readonly class EarthquakeArea
{
    public function __construct(
        public string $code,
        public string $name,
        public string $kana,
        public string $prefectureCode,
    ) {
        //
    }
}
