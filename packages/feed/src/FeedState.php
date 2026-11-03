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
     */
    public function __construct(
        public ?string $etag,
        public ?string $lastModified,
        public ?DateTimeImmutable $feedUpdatedAt,
        public DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $gapSince = null,
        public array $retryUrls = [],
        public ?string $subscription = null,
    ) {
        //
    }

    /**
     * @return array{etag: ?string, lastModified: ?string, feedUpdatedAt: ?string, fetchedAt: string, gapSince: ?string, retryUrls: list<string>, subscription: ?string}
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
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $retryUrls = $data['retryUrls'] ?? [];

        if (!is_array($retryUrls) || !array_is_list($retryUrls)) {
            throw new InvalidArgumentException('retryUrls must be a list of strings');
        }

        $urls = [];

        foreach ($retryUrls as $url) {
            if (!is_string($url)) {
                throw new InvalidArgumentException('retryUrls must be a list of strings');
            }

            $urls[] = $url;
        }

        return new self(
            self::optionalString($data, 'etag'),
            self::optionalString($data, 'lastModified'),
            self::optionalDateTime($data, 'feedUpdatedAt'),
            self::optionalDateTime($data, 'fetchedAt') ?? throw new InvalidArgumentException('fetchedAt is required'),
            self::optionalDateTime($data, 'gapSince'),
            $urls,
            self::optionalString($data, 'subscription'),
        );
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
