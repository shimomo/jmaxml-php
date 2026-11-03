<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;

/**
 * Where FeedClient keeps what it has seen and what it knows about each feed.
 *
 * Implement it on top of your cache or database. Only one poller should use a store at a time.
 *
 * @author shimomo
 */
interface StateStore
{
    public function isSeen(string $entryId): bool;

    /**
     * Seen entries may be forgotten after $expiresAt; by then they are gone from the long-term feed.
     */
    public function markSeen(string $entryId, DateTimeImmutable $expiresAt): void;

    public function load(string $feedUrl): ?FeedState;

    public function save(string $feedUrl, FeedState $state): void;
}
