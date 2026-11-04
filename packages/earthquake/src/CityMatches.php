<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

/**
 * The outcome of EarthquakeEvent::citiesAtLeast().
 *
 * @author shimomo
 */
final readonly class CityMatches
{
    /**
     * @param list<CityMatch> $matches in the order the cities were given
     * @param list<string> $unknownCityCodes city codes not in the code tables, so they could not be checked by area
     */
    public function __construct(
        public array $matches,
        public array $unknownCityCodes,
    ) {
        //
    }
}
