<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use JmaXml\Feed\FeedState;
use JmaXml\Feed\InMemoryStateStore;
use JmaXml\Feed\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class InMemoryStateStoreTest extends TestCase
{
    #[Test]
    public function forgetsSeenEntriesAfterTheyExpire(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $store = new InMemoryStateStore($clock);

        $store->markSeen('a', $clock->now()->modify('+8 days'));

        $this->assertTrue($store->isSeen('a'));
        $this->assertFalse($store->isSeen('b'));

        $clock->advance('+8 days +1 second');

        $this->assertFalse($store->isSeen('a'));
    }

    #[Test]
    public function marksManyEntriesQuickly(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $store = new InMemoryStateStore($clock);
        $expiresAt = $clock->now()->modify('+8 days');
        $started = microtime(true);

        for ($i = 0; $i < 20000; $i++) {
            $store->markSeen('https://www.data.jma.go.jp/developer/xml/data/' . $i . '.xml', $expiresAt);
        }

        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertTrue($store->isSeen('https://www.data.jma.go.jp/developer/xml/data/19999.xml'));
    }

    #[Test]
    public function storesFeedStatePerUrl(): void
    {
        $store = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z')));
        $state = new FeedState('"e1"', null, null, new DateTimeImmutable('2026-11-03T12:00:00Z'));

        $store->save('https://example.com/a.xml', $state);

        $this->assertSame($state, $store->load('https://example.com/a.xml'));
        $this->assertNull($store->load('https://example.com/b.xml'));
    }
}
