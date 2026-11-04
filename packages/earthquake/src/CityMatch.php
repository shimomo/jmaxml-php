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
     * @param SeismicIntensity|null $intensity the confirmed intensity of the city, or the strongest of its area; null when
     *                                         nothing is confirmed yet
     * @param bool $notYetReceived some observations of the city (or area) have not arrived and 5-lower or more is estimated:
     *                             the shaking may be stronger than $intensity, so check this flag rather than $intensity
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
