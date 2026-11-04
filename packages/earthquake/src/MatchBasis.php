<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

/**
 * What a city match was decided from.
 *
 * @author shimomo
 */
enum MatchBasis
{
    /**
     * The city's own intensity in 震源・震度に関する情報.
     */
    case City;

    /**
     * The intensity of the city's area (地震情報／細分区域) in 震度速報, applied to every city of the area.
     */
    case Area;
}
