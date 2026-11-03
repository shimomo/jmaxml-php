<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;
use JmaXml\Envelope;
use JmaXml\Exception\ParseException;
use JmaXml\Feed\Exception\FeedException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Polls one JMA feed per call and returns the telegrams it has not returned before.
 *
 * It has no loop or scheduler: call poll() yourself, no more often than every 60 seconds.
 *
 * @author shimomo
 */
final class FeedClient
{
    public const string DEFAULT_USER_AGENT = 'jmaxml-feed/0.1';

    public const string TELEGRAM_URL_PREFIX = 'https://www.data.jma.go.jp/developer/xml/data/';

    /**
     * @param int $gapThresholdSeconds a longer pause since the last successful poll may have missed telegrams
     *                                 (the high-frequency feed keeps at least 10 minutes)
     * @param int $seenRetentionSeconds how long entries stay marked seen (the long-term feed keeps about 7 days)
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StateStore $store,
        private readonly ClockInterface $clock,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        private readonly int $gapThresholdSeconds = 480,
        private readonly int $seenRetentionSeconds = 691200,
    ) {
        //
    }

    public function poll(Subscription $subscription): PollResult
    {
        $now = $this->clock->now();
        $highUrl = $subscription->feed->highFrequencyUrl();
        $state = $this->store->load($highUrl);

        if ($state === null || $now->getTimestamp() - $state->fetchedAt->getTimestamp() > $this->seenRetentionSeconds) {
            return $this->prime($subscription, $state, $now);
        }

        $gapSince = $state->gapSince
            ?? ($now->getTimestamp() - $state->fetchedAt->getTimestamp() > $this->gapThresholdSeconds ? $state->fetchedAt : null);

        [$feed, $etag, $lastModified] = $this->fetchFeed($highUrl, $state);
        $entries = $feed?->entries ?? [];
        $gap = null;

        if ($gapSince !== null) {
            [$longEntries, $longUpdatedAt] = $this->fetchLongTermFeed($subscription, $now);
            $entries = [...$longEntries, ...$entries];
            $gap = new Gap($gapSince, $longUpdatedAt !== null && $longUpdatedAt->getTimestamp() >= $now->getTimestamp() - $this->gapThresholdSeconds);
        }

        [$telegrams, $failures, $retryUrls] = $this->process($subscription, $entries, $state->retryUrls, $now);

        $this->store->save($highUrl, new FeedState(
            $feed === null ? $state->etag : $etag,
            $feed === null ? $state->lastModified : $lastModified,
            $feed?->updated ?? $state->feedUpdatedAt,
            $now,
            $gap !== null && !$gap->covered ? $gap->since : null,
            $retryUrls,
        ));

        return new PollResult($telegrams, $failures, $feed === null, false, $gap);
    }

    /**
     * Builds the state from scratch: everything currently in both feeds is marked seen and nothing is returned,
     * so that a first poll (or one after a pause longer than the seen retention) never reports old telegrams as new.
     */
    private function prime(Subscription $subscription, ?FeedState $previous, DateTimeImmutable $now): PollResult
    {
        $expiresAt = $now->modify('+' . $this->seenRetentionSeconds . ' seconds');

        foreach ([$subscription->feed->longTermUrl(), $subscription->feed->highFrequencyUrl()] as $url) {
            [$feed, $etag, $lastModified] = $this->fetchFeed($url, null);

            foreach ($feed?->entries ?? [] as $entry) {
                $this->store->markSeen($entry->id, $expiresAt);
            }

            $this->store->save($url, new FeedState($etag, $lastModified, $feed?->updated, $now));
        }

        return new PollResult([], [], false, true, $previous === null ? null : new Gap($previous->fetchedAt, false));
    }

    /**
     * @return array{list<Entry>, ?DateTimeImmutable} the long-term feed's entries (empty on 304) and when it was last updated
     */
    private function fetchLongTermFeed(Subscription $subscription, DateTimeImmutable $now): array
    {
        $url = $subscription->feed->longTermUrl();
        $state = $this->store->load($url);

        [$feed, $etag, $lastModified] = $this->fetchFeed($url, $state);
        $updatedAt = $feed?->updated ?? $state?->feedUpdatedAt;

        $this->store->save($url, new FeedState(
            $feed === null ? $state?->etag : $etag,
            $feed === null ? $state?->lastModified : $lastModified,
            $updatedAt,
            $now,
        ));

        return [$feed?->entries ?? [], $updatedAt];
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
        } catch (ClientExceptionInterface $exception) {
            throw new FeedException('Cannot fetch feed: ' . $exception->getMessage(), $url, null, $exception);
        }

        $status = $response->getStatusCode();

        if ($status === 304) {
            return [null, null, null];
        }

        if ($status !== 200) {
            throw new FeedException('Unexpected response', $url, $status);
        }

        return [AtomFeed::fromString((string) $response->getBody(), $url), self::header($response, 'ETag'), self::header($response, 'Last-Modified')];
    }

    /**
     * @param list<Entry> $entries
     * @param list<string> $retryUrls
     *
     * @return array{list<Telegram>, list<Failure>, list<string>} telegrams, failures, URLs to retry
     */
    private function process(Subscription $subscription, array $entries, array $retryUrls, DateTimeImmutable $now): array
    {
        $candidates = [];

        foreach ($entries as $entry) {
            $candidates[$entry->id] ??= $entry;
        }

        foreach ($retryUrls as $retryUrl) {
            $candidates[$retryUrl] ??= self::entryFromUrl($retryUrl, $now);
        }

        $pending = array_values(array_filter($candidates, fn(Entry $entry): bool => !$this->store->isSeen($entry->id)));
        usort($pending, static fn(Entry $a, Entry $b): int => $a->issuedAt() <=> $b->issuedAt());

        $expiresAt = $now->modify('+' . $this->seenRetentionSeconds . ' seconds');
        $telegrams = [];
        $failures = [];
        $retry = [];

        foreach ($pending as $entry) {
            if (!$subscription->wantsDataType($entry)) {
                $this->store->markSeen($entry->id, $expiresAt);

                continue;
            }

            if (!str_starts_with($entry->url, self::TELEGRAM_URL_PREFIX)) {
                $failures[] = new Failure($entry, 'Telegram URL is outside ' . self::TELEGRAM_URL_PREFIX, false);
                $this->store->markSeen($entry->id, $expiresAt);

                continue;
            }

            $download = $this->download($entry);

            if ($download instanceof Failure) {
                $failures[] = $download;
                $retry[] = $entry->id;

                continue;
            }

            try {
                $envelope = Envelope::fromString($download);
            } catch (ParseException $exception) {
                $failures[] = new Failure($entry, 'Cannot read telegram: ' . $exception->getMessage(), false, $exception);
                $this->store->markSeen($entry->id, $expiresAt);

                continue;
            }

            if ($subscription->wantsStatus($envelope->control->status)) {
                $telegrams[] = new Telegram($entry, $download, $envelope);
            }

            $this->store->markSeen($entry->id, $expiresAt);
        }

        return [$telegrams, $failures, $retry];
    }

    /**
     * @return string|Failure the telegram XML, or a retryable failure
     */
    private function download(Entry $entry): string|Failure
    {
        $request = $this->requests->createRequest('GET', $entry->url)->withHeader('User-Agent', $this->userAgent);

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            return new Failure($entry, 'Cannot download telegram: ' . $exception->getMessage(), true, $exception);
        }

        if ($response->getStatusCode() !== 200) {
            return new Failure($entry, 'Cannot download telegram: HTTP ' . $response->getStatusCode(), true);
        }

        return (string) $response->getBody();
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
