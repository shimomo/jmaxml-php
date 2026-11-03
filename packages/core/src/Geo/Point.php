<?php

declare(strict_types=1);

namespace JmaXml\Geo;

/**
 * A point decoded from an ISO 6709 string, in decimal degrees.
 *
 * @author shimomo
 */
final readonly class Point
{
    /**
     * @param float|null $altitude Metres; negative values are below ground or sea level (e.g. hypocenter depth).
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?float $altitude,
    ) {
        //
    }
}
