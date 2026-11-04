<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

use JmaXml\Report;
use JmaXml\Seismology;

/**
 * Splits a stream of telegrams into earthquake events.
 *
 * @author shimomo
 */
final class EarthquakeEvents
{
    /**
     * @param iterable<Report> $reports any telegrams; only the three kinds of earthquake report are used
     * @param list<string> $statuses Control/Status values to keep; drills (訓練) and tests (試験) only when listed
     *
     * @return list<EarthquakeEvent> one event per Control/Status and Head/EventID, oldest detection first (unknown last)
     */
    public static function group(iterable $reports, array $statuses = ['通常']): array
    {
        $groups = [];

        foreach ($reports as $report) {
            if (self::isEarthquakeReport($report) && in_array($report->control->status, $statuses, true)) {
                $groups[$report->control->status . "\0" . $report->head->eventID][] = $report;
            }
        }

        $events = array_map(EarthquakeEvent::fromReports(...), array_values($groups));

        usort($events, static function (EarthquakeEvent $a, EarthquakeEvent $b): int {
            if ($a->detectedAt === null || $b->detectedAt === null) {
                return ($a->detectedAt === null) <=> ($b->detectedAt === null);
            }

            return $a->detectedAt <=> $b->detectedAt;
        });

        return $events;
    }

    public static function isEarthquakeReport(Report $report): bool
    {
        return $report->body instanceof Seismology\Body
            && in_array($report->control->title, [EarthquakeEvent::SHINDO_SOKUHO, EarthquakeEvent::HYPOCENTER, EarthquakeEvent::HYPOCENTER_AND_INTENSITY], true);
    }
}
