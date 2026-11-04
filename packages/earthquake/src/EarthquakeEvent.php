<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

use DateTimeImmutable;
use InvalidArgumentException;
use JmaXml\Report;
use JmaXml\Seismology;

/**
 * One earthquake, built from all the 震度速報, 震源に関する情報 and 震源・震度に関する情報 telegrams about it.
 *
 * An event is identified by Control/Status and Head/EventID. Each kind of report (Control/Title) is a unit of
 * information whose latest telegram counts; a unit whose latest telegram is a cancellation (取消) no longer counts.
 * The event holds no state of its own: keep the telegrams and build the event again when a new one arrives.
 *
 * Region tables are keyed by code; PHP turns codes such as '211' into integer keys, so read codes from the readings.
 *
 * @author shimomo
 */
final readonly class EarthquakeEvent
{
    public const string SHINDO_SOKUHO = '震度速報';

    public const string HYPOCENTER = '震源に関する情報';

    public const string HYPOCENTER_AND_INTENSITY = '震源・震度に関する情報';

    private const string CANCELLATION = '取消';

    /**
     * @param array<array-key, IntensityReading> $areas 地震情報／細分区域: from the detailed report if it has intensities, else from 震度速報
     * @param array<array-key, IntensityReading> $cities 市町村等: from the detailed report (empty without one)
     * @param array<array-key, IntensityReading> $newerShindoSokuhoAreas areas of a 震度速報 newer than the detailed report (empty otherwise)
     */
    public function __construct(
        public string $status,
        public string $eventId,
        public EventStage $stage,
        public ?DateTimeImmutable $detectedAt,
        public ?DateTimeImmutable $originTime,
        public ?Hypocenter $hypocenter,
        public ?SeismicIntensity $maxIntensity,
        public DateTimeImmutable $lastReportedAt,
        public array $areas,
        public array $cities,
        public array $newerShindoSokuhoAreas,
    ) {
        //
    }

    /**
     * @param list<Report> $reports telegrams about one earthquake, in any order
     */
    public static function fromReports(array $reports): self
    {
        if ($reports === []) {
            throw new InvalidArgumentException('An earthquake event needs at least one report');
        }

        $key = null;
        $latest = [];
        $lastReportedAt = null;

        foreach ($reports as $report) {
            if (!EarthquakeEvents::isEarthquakeReport($report)) {
                throw new InvalidArgumentException('Not an earthquake report: ' . $report->control->title);
            }

            $reportKey = $report->control->status . "\0" . $report->head->eventID;
            $key ??= $reportKey;

            if ($reportKey !== $key) {
                throw new InvalidArgumentException('Reports of different earthquakes (EventID or Status differ)');
            }

            $title = $report->control->title;
            $current = $latest[$title] ?? null;

            if ($current === null || self::isNewer($report, $current)) {
                $latest[$title] = $report;
            }

            if ($lastReportedAt === null || $report->head->reportDateTime > $lastReportedAt) {
                $lastReportedAt = $report->head->reportDateTime;
            }
        }

        $first = $reports[0];
        $valid = array_filter($latest, static fn(Report $report): bool => $report->head->infoType !== self::CANCELLATION);

        if ($valid === []) {
            return new self($first->control->status, $first->head->eventID, EventStage::Cancelled, null, null, null, null, $lastReportedAt, [], [], []);
        }

        $shindo = $valid[self::SHINDO_SOKUHO] ?? null;
        $hypocenterReport = $valid[self::HYPOCENTER] ?? null;
        $detailed = $valid[self::HYPOCENTER_AND_INTENSITY] ?? null;

        $detailedObservation = $detailed === null ? null : self::body($detailed)->intensity?->observation;
        $shindoObservation = $shindo === null ? null : self::body($shindo)->intensity?->observation;
        $observation = $detailedObservation ?? $shindoObservation;

        $newerShindo = $detailedObservation !== null && $shindo !== null && $detailed !== null && self::isNewer($shindo, $detailed)
            ? $shindoObservation
            : null;

        $maxIntensity = SeismicIntensity::fromValue($observation?->maxInt);
        $newerMax = SeismicIntensity::fromValue($newerShindo?->maxInt);

        if ($newerMax !== null && ($maxIntensity === null || $newerMax->rank() > $maxIntensity->rank())) {
            $maxIntensity = $newerMax;
        }

        $earthquakeReport = $detailed ?? $hypocenterReport;
        $earthquake = $earthquakeReport === null ? null : (self::body($earthquakeReport)->earthquake[0] ?? null);

        $stage = match (true) {
            $detailed !== null => EventStage::WithHypocenterAndIntensity,
            $hypocenterReport !== null => EventStage::WithHypocenter,
            default => EventStage::IntensityOnly,
        };

        return new self(
            $first->control->status,
            $first->head->eventID,
            $stage,
            $shindo?->head->targetDateTime ?? $earthquake?->originTime ?? $earthquake?->arrivalTime,
            $earthquake?->originTime,
            self::hypocenter($earthquake),
            $maxIntensity,
            $lastReportedAt,
            self::areaReadings($observation),
            $detailedObservation === null ? [] : self::cityReadings($detailedObservation),
            self::areaReadings($newerShindo),
        );
    }

    public function isCancelled(): bool
    {
        return $this->stage === EventStage::Cancelled;
    }

    private static function isNewer(Report $candidate, Report $current): bool
    {
        return [$candidate->head->reportDateTime, $candidate->control->dateTime] > [$current->head->reportDateTime, $current->control->dateTime];
    }

    private static function body(Report $report): Seismology\Body
    {
        $body = $report->body;

        if (!$body instanceof Seismology\Body) {
            throw new InvalidArgumentException('Not an earthquake report: ' . $report->control->title);
        }

        return $body;
    }

    private static function hypocenter(?Seismology\Earthquake $earthquake): ?Hypocenter
    {
        $area = $earthquake?->hypocenter?->area;

        if ($earthquake === null || $area === null) {
            return null;
        }

        try {
            $point = ($area->coordinate[0] ?? null)?->points()[0] ?? null;
        } catch (InvalidArgumentException) {
            $point = null;
        }

        $magnitude = $earthquake->magnitude[0] ?? null;

        return new Hypocenter($area->name, $area->code->value, $point, $magnitude?->value, $magnitude?->description);
    }

    /**
     * @return array<array-key, IntensityReading>
     */
    private static function areaReadings(?Seismology\IntensityDetail $observation): array
    {
        $readings = [];

        foreach ($observation?->pref ?? [] as $pref) {
            foreach ($pref->area as $area) {
                $readings[$area->code] = IntensityReading::from($area->code, $area->name, $area->maxInt, $area->condition);
            }
        }

        return $readings;
    }

    /**
     * @return array<array-key, IntensityReading>
     */
    private static function cityReadings(Seismology\IntensityDetail $observation): array
    {
        $readings = [];

        foreach ($observation->pref as $pref) {
            foreach ($pref->area as $area) {
                foreach ($area->city as $city) {
                    $readings[$city->code] = IntensityReading::from($city->code, $city->name, $city->maxInt, $city->condition);
                }
            }
        }

        return $readings;
    }
}
