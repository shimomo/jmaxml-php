<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

/**
 * A registered city that may have reached the threshold.
 *
 * @author shimomo
 */
final readonly class CityMatch
{
    /**
     * @param SeismicIntensity|null $intensity the city's intensity, or its area's strongest one; null when not yet received
     */
    public function __construct(
        public string $cityCode,
        public ?SeismicIntensity $intensity,
        public bool $notYetReceived,
        public MatchBasis $basis,
    ) {
        //
    }
}
