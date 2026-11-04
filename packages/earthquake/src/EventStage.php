<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

/**
 * How far the information about an earthquake has come.
 *
 * @author shimomo
 */
enum EventStage
{
    /**
     * Only 震度速報 (intensity by area); no hypocenter yet.
     */
    case IntensityOnly;

    /**
     * 震源に関する情報, but no 震源・震度に関する情報 yet.
     */
    case WithHypocenter;

    /**
     * 震源・震度に関する情報 (hypocenter and intensity by city).
     */
    case WithHypocenterAndIntensity;

    /**
     * Every kind of report about the earthquake has been cancelled.
     */
    case Cancelled;
}
