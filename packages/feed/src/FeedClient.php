<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;
use InvalidArgumentException;
use JmaXml\Envelope;
use JmaXml\Exception\ParseException;
use JmaXml\Feed\Exception\FeedException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Polls one JMA feed per call and returns the telegrams it has not returned before.
 *
 * It has no loop or scheduler: call poll() yourself, every 60 to 120 seconds.
 *
 * Each poll runs in three phases: fetch the feeds, decide what to do with every entry (without writing anything),
 * then mark entries seen and save the feed states. An exception before the last phase leaves the store untouched,
 * so the next poll sees the same entries again.
 *
 * @author shimomo
 */
final class FeedClient
{
    public const string DEFAULT_USER_AGENT = 'jmaxml-feed/0.1';

    public const string TELEGRAM_URL_PREFIX = 'https://www.data.jma.go.jp/developer/xml/data/';

    /**
     * The high-frequency feed keeps at least the last 10 minutes.
     */
    private const int HIGH_FREQUENCY_WINDOW_SECONDS = 600;

    /**
     * The long-term feed keeps about the last 7 days.
     */
    private const int LONG_TERM_WINDOW_SECONDS = 604800;

    /**
     * How long a telegram that looks missing (a 4xx other than 408 and 429) or unreadable keeps being retried, counted
     * from its first such failure. Behind CloudFront a new object can answer 403 or 404 for a while, a WAF block or
     * rate limit also answers 403, and a broken body can stay cached for a minute.
     */
    private const int GIVE_UP_AFTER_SECONDS = 600;

    /**
     * @param int $gapThresholdSeconds how far the feed may have moved on since the last successful poll before telegrams
     *                                 may have been missed; below the 10-minute window of the high-frequency feed
     * @param int $catchUpLimitSeconds after a longer pause the state is rebuilt instead of catching up; below the 7 days
     *                                 of the long-term feed
     * @param int $seenRetentionSeconds how long entries stay marked seen; longer than the long-term feed keeps them
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StateStore $store,
        private readonly ClockInterface $clock,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        private readonly int $gapThresholdSeconds = 480,
        private readonly int $catchUpLimitSeconds = 518400,
        private readonly int $seenRetentionSeconds = 691200,
    ) {
        if ($gapThresholdSeconds <= 0 || $gapThresholdSeconds >= self::HIGH_FREQUENCY_WINDOW_SECONDS) {
            throw new InvalidArgumentException('gapThresholdSeconds must be between 1 and 599');
        }

        if ($catchUpLimitSeconds <= $gapThresholdSeconds || $catchUpLimitSeconds >= self::LONG_TERM_WINDOW_SECONDS) {
            throw new InvalidArgumentException('catchUpLimitSeconds must be longer than gapThresholdSeconds and shorter than 7 days');
        }

        if ($seenRetentionSeconds < self::LONG_TERM_WINDOW_SECONDS + 43200) {
            throw new InvalidArgumentException('seenRetentionSeconds must be at least 7.5 days, longer than the long-term feed keeps entries');
        }
    }

    public function poll(Subscription $subscription): PollResult
    {
        $now = $this->clock->now();
        $highUrl = $subscription->feed->highFrequencyUrl();
        $longUrl = $subscription->feed->longTermUrl();
        $state = $this->store->load($highUrl);

        $owner = $state?->subscription;

        if ($owner !== null && $owner !== $subscription->fingerprint()) {
            throw new LogicException(
                'This StateStore holds the state of another Subscription for ' . $highUrl
                . '. Use one Subscription per feed and store (list every data type code in it), or a separate StateStore.',
            );
        }

        if ($state === null || $now->getTimestamp() - $state->fetchedAt->getTimestamp() > $this->catchUpLimitSeconds) {
            return $this->prime($subscription, $state, $now);
        }

        // Phase 1: fetch. Nothing is written until phase 3.
        [$feed, $etag, $lastModified] = $this->fetchFeed($highUrl, $state);
        $highUpdatedAt = $feed?->updated ?? $state->feedUpdatedAt;
        $warnings = $feed?->warnings ?? [];
        $entries = $feed?->entries ?? [];

        // A 304 proves the feed has not changed since the last poll. Otherwise compare the feed's own timestamp with the
        // last poll: the feed keeps at least ten minutes before its <updated>.
        $gapSince = $state->gapSince
            ?? ($feed !== null && $feed->updated->getTimestamp() - $state->fetchedAt->getTimestamp() > $this->gapThresholdSeconds ? $state->fetchedAt : null);

        $gap = null;
        $longState = null;

        if ($gapSince !== null) {
            $previousLong = $this->store->load($longUrl);

            try {
                [$longFeed, $longEtag, $longLastModified] = $this->fetchFeed($longUrl, $previousLong);
                $longUpdatedAt = $longFeed?->updated ?? $previousLong?->feedUpdatedAt;
                $longState = new FeedState(
                    $longFeed === null ? $previousLong?->etag : $longEtag,
                    $longFeed === null ? $previousLong?->lastModified : $longLastModified,
                    $longUpdatedAt,
                    $now,
                );
                $entries = [...($longFeed?->entries ?? []), ...$entries];
                $warnings = [...$warnings, ...($longFeed?->warnings ?? [])];
            } catch (FeedException $exception) {
                $longUpdatedAt = null;
                $warnings[] = 'Long-term feed: ' . $exception->getMessage();
            }

            $covered = $longUpdatedAt !== null && $highUpdatedAt !== null
                && $longUpdatedAt->getTimestamp() >= $highUpdatedAt->getTimestamp() - $this->gapThresholdSeconds;
            $gap = new Gap($gapSince, $covered);
        }

        // Phase 2: decide. Downloads happen here; their failures become Failure objects, never exceptions.
        [$telegrams, $failures, $retryUrls, $seen, $failingSince] = $this->process($subscription, $entries, $state->retryUrls, $state->failingSince, $now);

        // Phase 3: commit.
        $expiresAt = $now->modify('+' . $this->seenRetentionSeconds . ' seconds');

        foreach ($seen as $entryId) {
            $this->store->markSeen($entryId, $expiresAt);
        }

        if ($longState !== null) {
            $this->store->save($longUrl, $longState);
        }

        $this->store->save($highUrl, new FeedState(
            $feed === null ? $state->etag : $etag,
            $feed === null ? $state->lastModified : $lastModified,
            $highUpdatedAt,
            $now,
            $gap !== null && !$gap->covered ? $gap->since : null,
            $retryUrls,
            $subscription->fingerprint(),
            $failingSince,
        ));

        return new PollResult($telegrams, $failures, $feed === null, false, $gap, $warnings);
    }

    /**
     * Builds the state from scratch: everything currently in both feeds is marked seen and nothing is returned,
     * so that a first poll (or one after a pause longer than the catch-up limit) never reports old telegrams as new.
     */
    private function prime(Subscription $subscription, ?FeedState $previous, DateTimeImmutable $now): PollResult
    {
        $fetched = [];

        foreach ([$subscription->feed->longTermUrl(), $subscription->feed->highFrequencyUrl()] as $url) {
            $fetched[$url] = $this->fetchFeed($url, null);
        }

        $expiresAt = $now->modify('+' . $this->seenRetentionSeconds . ' seconds');
        $warnings = [];

        foreach ($fetched as $url => [$feed, $etag, $lastModified]) {
            foreach ($feed?->entries ?? [] as $entry) {
                $this->store->markSeen($entry->url, $expiresAt);
            }

            $warnings = [...$warnings, ...($feed?->warnings ?? [])];
            $isHigh = $url === $subscription->feed->highFrequencyUrl();
            $this->store->save($url, new FeedState($etag, $lastModified, $feed?->updated, $now, null, [], $isHigh ? $subscription->fingerprint() : null));
        }

        return new PollResult([], [], false, true, $previous === null ? null : new Gap($previous->fetchedAt, false), $warnings);
    }

    /**
     * @return array{?AtomFeed, ?string, ?string} the feed (null on 304), its ETag and Last-Modified
     */
    private function fetchFeed(string $url, ?FeedState $state): array
    {
        $request = $this->requests->createRequest('GET', $url)->withHeader('User-Agent', $this->userAgent);
        $etag = $state?->etag;
        $lastModified = $state?->lastModified;

        if ($etag !== null) {
            $request = $request->withHeader('If-None-Match', $etag);
        }

        if ($lastModified !== null) {
            $request = $request->withHeader('If-Modified-Since', $lastModified);
        }

        try {
            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();
            $body = $status === 200 ? (string) $response->getBody() : '';
        } catch (ClientExceptionInterface|RuntimeException $exception) {
            throw new FeedException('Cannot fetch feed: ' . $exception->getMessage(), $url, null, $exception);
        }

        if ($status === 304) {
            return [null, null, null];
        }

        if ($status !== 200) {
            throw new FeedException('Unexpected response', $url, $status);
        }

        return [AtomFeed::fromString($body, $url), self::header($response, 'ETag'), self::header($response, 'Last-Modified')];
    }

    /**
     * Decides what to do with every unseen entry. Writes nothing to the store.
     *
     * Entries are identified by their link (the telegram URL), which is also what gets downloaded.
     *
     * @param list<Entry> $entries
     * @param list<string> $retryUrls
     * @param array<string, DateTimeImmutable> $failingSince when each telegram that looks missing or unreadable first failed
     *
     * @return array{list<Telegram>, list<Failure>, list<string>, list<string>, array<string, DateTimeImmutable>}
     *     telegrams, failures, URLs to retry, URLs to mark seen, telegrams that still look missing or unreadable
     */
    private function process(Subscription $subscription, array $entries, array $retryUrls, array $failingSince, DateTimeImmutable $now): array
    {
        $candidates = [];

        foreach ($entries as $entry) {
            $candidates[$entry->url] ??= $entry;
        }

        foreach ($retryUrls as $retryUrl) {
            $candidates[$retryUrl] ??= self::entryFromUrl($retryUrl, $now);
        }

        $pending = array_values(array_filter($candidates, fn(Entry $entry): bool => !$this->store->isSeen($entry->url)));
        usort($pending, static fn(Entry $a, Entry $b): int => $a->issuedAt() <=> $b->issuedAt());

        $telegrams = [];
        $failures = [];
        $retry = [];
        $seen = [];
        $stillFailing = [];

        foreach ($pending as $entry) {
            if ($subscription->dataTypeCodes !== [] && $entry->file === null) {
                $failures[] = new Failure($entry, 'Unrecognised telegram file name, so its data type code is unknown', false);
                $seen[] = $entry->url;

                continue;
            }

            if (!$subscription->wantsDataType($entry)) {
                $seen[] = $entry->url;

                continue;
            }

            if (!str_starts_with($entry->url, self::TELEGRAM_URL_PREFIX)) {
                $failures[] = new Failure($entry, 'Telegram URL is outside ' . self::TELEGRAM_URL_PREFIX, false);
                $seen[] = $entry->url;

                continue;
            }

            $downloaded = self::read($entry, $this->download($entry));

            if ($downloaded instanceof Telegram) {
                if ($subscription->wantsStatus($downloaded->envelope->control->status)) {
                    $telegrams[] = $downloaded;
                }

                $seen[] = $entry->url;

                continue;
            }

            if ($downloaded->willRetry) {
                $failures[] = $downloaded;
                $retry[] = $entry->url;

                continue;
            }

            // The telegram looks missing or unreadable, which may still pass: give up only once it has failed this way
            // for GIVE_UP_AFTER_SECONDS.
            $since = $failingSince[$entry->url] ?? $now;

            if ($now->getTimestamp() - $since->getTimestamp() < self::GIVE_UP_AFTER_SECONDS) {
                $failures[] = new Failure($entry, $downloaded->reason, true, $downloaded->previous);
                $retry[] = $entry->url;
                $stillFailing[$entry->url] = $since;
            } else {
                $failures[] = $downloaded;
                $seen[] = $entry->url;
            }
        }

        return [$telegrams, $failures, $retry, $seen, $stillFailing];
    }

    /**
     * @return string|Failure the telegram XML, or a failure: retryable for 5xx, 408, 429 and transport errors (including a
     *                        body that cannot be read); for other 4xx (403/404 for a missing object) willRetry is false,
     *                        and process() decides how long to keep trying
     */
    private function download(Entry $entry): string|Failure
    {
        $request = $this->requests->createRequest('GET', $entry->url)->withHeader('User-Agent', $this->userAgent);

        try {
            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                $permanent = $status >= 400 && $status < 500 && $status !== 408 && $status !== 429;

                return new Failure($entry, 'Cannot download telegram: HTTP ' . $status, !$permanent);
            }

            return (string) $response->getBody();
        } catch (ClientExceptionInterface|RuntimeException $exception) {
            return new Failure($entry, 'Cannot download telegram: ' . $exception->getMessage(), true, $exception);
        }
    }

    /**
     * @param string|Failure $download the telegram XML, or the failure to download it
     */
    private static function read(Entry $entry, string|Failure $download): Telegram|Failure
    {
        if ($download instanceof Failure) {
            return $download;
        }

        try {
            return new Telegram($entry, $download, Envelope::fromString($download));
        } catch (ParseException $exception) {
            // A truncated body or an error page served with 200.
            return new Failure($entry, 'Cannot read telegram: ' . $exception->getMessage(), false, $exception);
        }
    }

    private static function entryFromUrl(string $url, DateTimeImmutable $now): Entry
    {
        $file = TelegramFileName::fromUrl($url);

        return new Entry($url, '', $file?->createdAt ?? $now, '', $url, '', $file);
    }

    private static function header(ResponseInterface $response, string $name): ?string
    {
        $value = $response->getHeaderLine($name);

        return $value === '' ? null : $value;
    }
}
