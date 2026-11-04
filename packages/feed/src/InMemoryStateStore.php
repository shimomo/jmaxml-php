<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * A StateStore that lives only as long as the process. Useful for tests and short scripts.
 *
 * @author shimomo
 */
final class InMemoryStateStore implements StateStore
{
    /**
     * @var array<string, DateTimeImmutable> telegram URL => expiry
     */
    private array $seen = [];

    /**
     * @var array<string, FeedState>
     */
    private array $states = [];

    private int $marksSinceSweep = 0;

    public function __construct(private readonly ClockInterface $clock)
    {
        //
    }

    #[Override]
    public function isSeen(string $url): bool
    {
        return isset($this->seen[$url]) && $this->seen[$url] > $this->clock->now();
    }

    #[Override]
    public function markSeen(string $url, DateTimeImmutable $expiresAt): void
    {
        // Expired entries are swept once every 1,000 marks, so that priming a big feed stays linear.
        if (++$this->marksSinceSweep >= 1000) {
            $now = $this->clock->now();
            $this->seen = array_filter($this->seen, static fn(DateTimeImmutable $expiry): bool => $expiry > $now);
            $this->marksSinceSweep = 0;
        }

        $this->seen[$url] = $expiresAt;
    }

    #[Override]
    public function load(string $feedUrl): ?FeedState
    {
        return $this->states[$feedUrl] ?? null;
    }

    #[Override]
    public function save(string $feedUrl, FeedState $state): void
    {
        $this->states[$feedUrl] = $state;
    }
}
