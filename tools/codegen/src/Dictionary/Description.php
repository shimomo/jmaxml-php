<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Dictionary;

/**
 * One dictionary entry: the 意味 (meaning) and 解説 (explanation) columns.
 *
 * @author shimomo
 */
final readonly class Description
{
    public function __construct(
        public string $meaning,
        public string $explanation,
    ) {
        //
    }
}
