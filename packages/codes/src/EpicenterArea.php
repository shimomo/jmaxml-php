<?php

declare(strict_types=1);

namespace JmaXml\Codes;

/**
 * 震央地名（AreaEpicenter）の 1 件。
 *
 * @author shimomo
 */
final readonly class EpicenterArea
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
        //
    }
}
