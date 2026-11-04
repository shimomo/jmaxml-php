<?php

declare(strict_types=1);

namespace JmaXml\Earthquake;

/**
 * The JMA seismic intensity scale (震度) as written in telegrams.
 *
 * @author shimomo
 */
enum SeismicIntensity: string
{
    case One = '1';
    case Two = '2';
    case Three = '3';
    case Four = '4';
    case FiveLower = '5-';
    case FiveUpper = '5+';
    case SixLower = '6-';
    case SixUpper = '6+';
    case Seven = '7';

    /**
     * Reads a MaxInt or Int value. Anything else (empty, "震度５弱以上未入電", unknown) gives null.
     */
    public static function fromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(trim($value));
    }

    public function rank(): int
    {
        return match ($this) {
            self::One => 1,
            self::Two => 2,
            self::Three => 3,
            self::Four => 4,
            self::FiveLower => 5,
            self::FiveUpper => 6,
            self::SixLower => 7,
            self::SixUpper => 8,
            self::Seven => 9,
        };
    }

    public function isAtLeast(self $threshold): bool
    {
        return $this->rank() >= $threshold->rank();
    }

    public function label(): string
    {
        return '震度' . str_replace(['-', '+'], ['弱', '強'], $this->value);
    }
}
