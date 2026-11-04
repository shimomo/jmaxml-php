<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

use DateTimeImmutable;
use InvalidArgumentException;
use JmaXml\Codes\EarthquakeCodes;
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
     * @param array<array-key, Report> $reports telegrams about one earthquake, in any order
     */
    public static function fromReports(array $reports): self
    {
        $reports = array_values($reports);

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

    /**
     * Which of the given cities may have reached the threshold.
     *
     * With 震源・震度に関する情報, cities are judged by their own intensity; areas of a 震度速報 that is newer than it are
     * added by area. With only 震度速報, every city of an area that reached the threshold matches. A city whose intensity
     * was not yet received (震度５弱以上未入電) matches any threshold.
     *
     * @param list<string> $cityCodes 気象・地震・火山情報／市町村等 codes, e.g. registered addresses
     */
    public function citiesAtLeast(SeismicIntensity $threshold, array $cityCodes): CityMatches
    {
        if ($this->isCancelled()) {
            return new CityMatches([], []);
        }

        $areaSource = $this->cities !== [] ? $this->newerShindoSokuhoAreas : $this->areas;
        $matches = [];
        $unknown = [];

        foreach ($cityCodes as $cityCode) {
            $city = $this->cities[$cityCode] ?? null;

            if ($city !== null && $city->mayBeAtLeast($threshold)) {
                $matches[] = new CityMatch($cityCode, $city->maxIntensity, $city->notYetReceived, MatchBasis::City);

                continue;
            }

            // A code the tables do not know can be checked neither by city nor by area: report it whatever the stage.
            $areaCode = EarthquakeCodes::city($cityCode)?->areaCode;

            if ($areaCode === null) {
                $unknown[] = $cityCode;

                continue;
            }

            $area = $areaSource[$areaCode] ?? null;

            if ($area !== null && $area->mayBeAtLeast($threshold)) {
                $matches[] = new CityMatch($cityCode, $area->maxIntensity, $area->notYetReceived, MatchBasis::Area);
            }
        }

        return new CityMatches($matches, $unknown);
    }

    /**
     * Whether another earthquake may be the same shaking: detected within the window and shaking at least one common
     * area with intensity 3 or more. Events are never merged; deciding what to do is up to the caller.
     */
    public function isPossiblyRelatedTo(self $other, int $windowSeconds = 300): bool
    {
        if ($this->status !== $other->status || $this->eventId === $other->eventId || $this->isCancelled() || $other->isCancelled()) {
            return false;
        }

        if ($this->detectedAt === null || $other->detectedAt === null || abs($this->detectedAt->getTimestamp() - $other->detectedAt->getTimestamp()) > $windowSeconds) {
            return false;
        }

        return array_intersect($this->shakenAreaCodes(), $other->shakenAreaCodes()) !== [];
    }

    /**
     * @return list<string>
     */
    private function shakenAreaCodes(): array
    {
        $codes = [];

        foreach ([...array_values($this->areas), ...array_values($this->newerShindoSokuhoAreas)] as $reading) {
            if ($reading->mayBeAtLeast(SeismicIntensity::Three)) {
                $codes[] = $reading->code;
            }
        }

        return $codes;
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
     * Area readings. In 震源・震度に関する情報 an area also counts as not yet received when one of its cities is.
     *
     * @return array<array-key, IntensityReading>
     */
    private static function areaReadings(?Seismology\IntensityDetail $observation): array
    {
        $readings = [];

        foreach ($observation?->pref ?? [] as $pref) {
            foreach ($pref->area as $area) {
                $pending = self::isNotYetReceived($area->condition);

                foreach ($area->city as $city) {
                    $pending = $pending || self::cityIsNotYetReceived($city);
                }

                $readings[$area->code] = new IntensityReading($area->code, $area->name, SeismicIntensity::fromValue($area->maxInt), $pending);
            }
        }

        return $readings;
    }

    /**
     * City readings. JMA marks a city with Condition 震度５弱以上未入電 only while its confirmed intensity is below 5-lower;
     * a city already confirmed at 5-lower or more can still have points whose Int is 震度５弱以上未入電, so those count too.
     *
     * @return array<array-key, IntensityReading>
     */
    private static function cityReadings(Seismology\IntensityDetail $observation): array
    {
        $readings = [];

        foreach ($observation->pref as $pref) {
            foreach ($pref->area as $area) {
                foreach ($area->city as $city) {
                    $readings[$city->code] = new IntensityReading($city->code, $city->name, SeismicIntensity::fromValue($city->maxInt), self::cityIsNotYetReceived($city));
                }
            }
        }

        return $readings;
    }

    private static function cityIsNotYetReceived(Seismology\IntensityCity $city): bool
    {
        if (self::isNotYetReceived($city->condition)) {
            return true;
        }

        foreach ($city->intensityStation as $station) {
            if (self::isNotYetReceived($station->int)) {
                return true;
            }
        }

        return false;
    }

    private static function isNotYetReceived(?string $value): bool
    {
        return $value !== null && str_contains($value, '未入電');
    }
}
