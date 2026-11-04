<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

/**
 * The strongest shaking reported for one region (prefecture, area or city) in one telegram.
 *
 * @author shimomo
 */
final readonly class IntensityReading
{
    public const string NOT_YET_RECEIVED = '震度５弱以上未入電';

    /**
     * @param bool $notYetReceived intensity 5-lower or stronger is estimated, but no observation has arrived yet
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?SeismicIntensity $maxIntensity,
        public bool $notYetReceived,
    ) {
        //
    }

    public static function from(string $code, string $name, ?string $maxInt, ?string $condition): self
    {
        return new self($code, $name, SeismicIntensity::fromValue($maxInt), $condition !== null && str_contains($condition, '未入電'));
    }

    /**
     * Whether the observed intensity reaches the threshold.
     */
    public function isAtLeast(SeismicIntensity $threshold): bool
    {
        return $this->maxIntensity?->isAtLeast($threshold) ?? false;
    }

    /**
     * Whether the region may have reached the threshold: observed, or not yet received (which can be any strength).
     */
    public function mayBeAtLeast(SeismicIntensity $threshold): bool
    {
        return $this->isAtLeast($threshold) || $this->notYetReceived;
    }
}
