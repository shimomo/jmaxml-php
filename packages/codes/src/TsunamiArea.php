<?php

declare(strict_types=1);

namespace JmaXml\Codes;

/**
 * 津波予報区（AreaTsunami）の 1 件。note は気象庁の備考（「津波予報区」「領域表現（…）」など）。
 *
 * @author shimomo
 */
final readonly class TsunamiArea
{
    public function __construct(
        public string $code,
        public string $name,
        public string $kana,
        public string $note,
    ) {
        //
    }
}
