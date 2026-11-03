<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests\Support;

use DateTimeImmutable;
use JmaXml\Feed\FeedState;
use JmaXml\Feed\StateStore;
use Override;
use RuntimeException;

/**
 * Wraps a StateStore and makes the first markSeen() throw, like a database blip.
 *
 * @author shimomo
 */
final class FailingOnceStateStore implements StateStore
{
    private bool $failed = false;

    public function __construct(private readonly StateStore $inner)
    {
        //
    }

    #[Override]
    public function isSeen(string $entryId): bool
    {
        return $this->inner->isSeen($entryId);
    }

    #[Override]
    public function markSeen(string $entryId, DateTimeImmutable $expiresAt): void
    {
        if (!$this->failed) {
            $this->failed = true;

            throw new RuntimeException('Database unavailable');
        }

        $this->inner->markSeen($entryId, $expiresAt);
    }

    #[Override]
    public function load(string $feedUrl): ?FeedState
    {
        return $this->inner->load($feedUrl);
    }

    #[Override]
    public function save(string $feedUrl, FeedState $state): void
    {
        $this->inner->save($feedUrl, $state);
    }
}
