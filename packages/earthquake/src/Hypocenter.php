<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

use JmaXml\Geo\Point;

/**
 * Where and how large the earthquake was. The point's altitude is negative for depth.
 *
 * @author shimomo
 */
final readonly class Hypocenter
{
    public function __construct(
        public string $name,
        public string $code,
        public ?Point $point,
        public ?float $magnitude,
        public ?string $magnitudeDescription,
    ) {
        //
    }
}
