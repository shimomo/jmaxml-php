<?php

declare(strict_types=1);

namespace JmaXml\Geo;

/**
 * Adds ISO 6709 decoding to the generated ElementBasis\Coordinate model.
 *
 * @property-read string $value
 * @property-read string|null $type
 *
 * @author shimomo
 */
trait HasPoints
{
    /**
     * @return list<Point>
     */
    public function points(): array
    {
        return Iso6709::parse($this->value, $this->type);
    }
}
