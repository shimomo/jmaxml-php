<?php

declare(strict_types=1);

namespace JmaXml\Codes;

/**
 * 地震情報／都道府県等（AreaInformationPrefectureEarthquake）の 1 件。
 *
 * @author shimomo
 */
final readonly class Prefecture
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
        //
    }
}
