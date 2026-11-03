<?php

declare(strict_types=1);

namespace JmaXml\Codes;

/**
 * 気象・地震・火山情報／市町村等（AreaInformationCity）の 1 件。政令指定都市は区単位。
 *
 * @author shimomo
 */
final readonly class City
{
    public function __construct(
        public string $code,
        public string $name,
        public string $kana,
        public string $areaCode,
    ) {
        //
    }
}
