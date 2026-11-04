<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;
use InvalidArgumentException;
use JmaXml\Feed\Internal\W3cDateTime;

/**
 * What a poll remembers about one feed URL. toArray() gives plain strings, so it can be stored anywhere.
 *
 * @author shimomo
 */
final readonly class FeedState
{
    /**
     * @param DateTimeImmutable|null $feedUpdatedAt the feed's own <updated> when it was last read
     * @param DateTimeImmutable $fetchedAt when the feed was last fetched successfully (200 or 304)
     * @param DateTimeImmutable|null $gapSince start of a gap not yet covered (high-frequency feed only)
     * @param list<string> $retryUrls telegrams to download again after a transient failure (high-frequency feed only)
     * @param string|null $subscription fingerprint of the Subscription that uses this state (high-frequency feed only)
     * @param array<string, DateTimeImmutable> $failingSince telegrams that look missing or unreadable, with the time of their
     *                                                   first such failure; they are retried for a while (high-frequency feed only)
     */
    public function __construct(
        public ?string $etag,
        public ?string $lastModified,
        public ?DateTimeImmutable $feedUpdatedAt,
        public DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $gapSince = null,
        public array $retryUrls = [],
        public ?string $subscription = null,
        public array $failingSince = [],
    ) {
        //
    }

    /**
     * @return array{etag: ?string, lastModified: ?string, feedUpdatedAt: ?string, fetchedAt: string, gapSince: ?string, retryUrls: list<string>, subscription: ?string, failingSince: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'etag' => $this->etag,
            'lastModified' => $this->lastModified,
            'feedUpdatedAt' => $this->feedUpdatedAt?->format(DATE_ATOM),
            'fetchedAt' => $this->fetchedAt->format(DATE_ATOM),
            'gapSince' => $this->gapSince?->format(DATE_ATOM),
            'retryUrls' => $this->retryUrls,
            'subscription' => $this->subscription,
            'failingSince' => array_map(static fn(DateTimeImmutable $since): string => $since->format(DATE_ATOM), $this->failingSince),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::optionalString($data, 'etag'),
            self::optionalString($data, 'lastModified'),
            self::optionalDateTime($data, 'feedUpdatedAt'),
            self::optionalDateTime($data, 'fetchedAt') ?? throw new InvalidArgumentException('fetchedAt is required'),
            self::optionalDateTime($data, 'gapSince'),
            self::stringList($data, 'retryUrls'),
            self::optionalString($data, 'subscription'),
            self::dateTimeMap($data, 'failingSince'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $values = $data[$key] ?? [];

        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException($key . ' must be a list of strings');
        }

        $strings = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException($key . ' must be a list of strings');
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, DateTimeImmutable>
     */
    private static function dateTimeMap(array $data, string $key): array
    {
        $values = $data[$key] ?? [];

        if (!is_array($values)) {
            throw new InvalidArgumentException($key . ' must map URLs to date-times');
        }

        $map = [];

        foreach ($values as $url => $value) {
            if (!is_string($url) || !is_string($value)) {
                throw new InvalidArgumentException($key . ' must map URLs to date-times');
            }

            $map[$url] = W3cDateTime::parse($value) ?? throw new InvalidArgumentException($key . ' has a value that is not a date-time: ' . $value);
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException($key . ' must be a string or null');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalDateTime(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::optionalString($data, $key);

        if ($value === null) {
            return null;
        }

        return W3cDateTime::parse($value) ?? throw new InvalidArgumentException($key . ' is not a date-time: ' . $value);
    }
}
