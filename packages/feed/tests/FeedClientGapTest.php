<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedState;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\Gap;
use JmaXml\Feed\InMemoryStateStore;
use JmaXml\Feed\Subscription;
use JmaXml\Feed\Telegram;
use JmaXml\Feed\Tests\Support\AtomFixture;
use JmaXml\Feed\Tests\Support\FakeHttpClient;
use JmaXml\Feed\Tests\Support\FrozenClock;
use JmaXml\Tests\Support\Fixtures;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * First polls, long pauses and gaps.
 *
 * @author shimomo
 */
final class FeedClientGapTest extends TestCase
{
    private const string HIGH = 'https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml';

    private const string LONG = 'https://www.data.jma.go.jp/developer/xml/feed/eqvol_l.xml';

    #[Test]
    public function firstPollPrimesWithoutReturningTelegrams(): void
    {
        [$client, $http, $store, $clock] = $this->client(null);
        $old = AtomFixture::url('20261103113000_0_VXSE51_270000.xml');
        $both = AtomFixture::url('20261103115500_0_VXSE51_270000.xml');
        $latest = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::LONG, self::feed('2026-11-03T11:58:00Z', [$old, $both], '"l1"'));
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [$both, $latest], '"h1"'));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertTrue($result->primed);
        $this->assertSame([], $result->telegrams);
        $this->assertNull($result->gap);
        $this->assertCount(2, $http->requests());
        $this->assertTrue($store->isSeen($old));
        $this->assertTrue($store->isSeen($both));
        $this->assertTrue($store->isSeen($latest));
        $this->assertSame('2026-11-03T12:00:00+00:00', $store->load(self::HIGH)?->fetchedAt->format(DATE_ATOM));
        $this->assertSame('"l1"', $store->load(self::LONG)?->etag);

        $clock->advance('+1 minute');
        $next = AtomFixture::url('20261103120030_0_VXSE51_270000.xml');
        $http->queue(self::HIGH, self::feed('2026-11-03T12:00:58Z', [$latest, $next], '"h2"'));
        $http->queue($next, self::telegram());

        $second = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertFalse($second->primed);
        $this->assertSame([$next], self::ids($second->telegrams));
        $this->assertSame('"h1"', $http->requestsTo(self::HIGH)[1]->getHeaderLine('If-None-Match'));
    }

    #[Test]
    public function shortGapIsCoveredByTheLongTermFeed(): void
    {
        [$client, $http, $store] = $this->client('2026-11-03T11:51:00Z');
        $missed = AtomFixture::url('20261103115500_0_VXSE51_270000.xml');
        $latest = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [$latest], '"h2"'));
        $http->queue(self::LONG, self::feed('2026-11-03T11:59:00Z', [$missed], '"l1"'));
        $http->queue($missed, self::telegram());
        $http->queue($latest, self::telegram());

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([$missed, $latest], self::ids($result->telegrams));
        $this->assertEquals(new Gap(new DateTimeImmutable('2026-11-03T11:51:00Z'), true), $result->gap);
        $this->assertNull($store->load(self::HIGH)?->gapSince);
        $this->assertSame('"l1"', $store->load(self::LONG)?->etag);
    }

    #[Test]
    public function gapStaysOpenUntilTheLongTermFeedCatchesUp(): void
    {
        [$client, $http, $store, $clock] = $this->client('2026-11-03T11:20:00Z');
        $factory = new Psr17Factory();
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [], '"h2"'), $factory->createResponse(304), $factory->createResponse(304));
        $http->queue(self::LONG, self::feed('2026-11-03T11:00:00Z', [], '"l1"'), $factory->createResponse(304));

        $first = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $since = new DateTimeImmutable('2026-11-03T11:20:00Z');
        $this->assertEquals(new Gap($since, false), $first->gap);
        $this->assertEquals($since, $store->load(self::HIGH)?->gapSince);

        $clock->advance('+1 minute');
        $second = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertEquals(new Gap($since, false), $second->gap);
        $this->assertSame('"l1"', $http->requestsTo(self::LONG)[1]->getHeaderLine('If-None-Match'));

        $clock->advance('+1 minute');
        $missed = AtomFixture::url('20261103114000_0_VXSE51_270000.xml');
        $http->queue(self::LONG, self::feed('2026-11-03T12:01:00Z', [$missed], '"l2"'));
        $http->queue($missed, self::telegram());

        $third = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([$missed], self::ids($third->telegrams));
        $this->assertEquals(new Gap($since, true), $third->gap);
        $this->assertNull($store->load(self::HIGH)?->gapSince);
    }

    #[Test]
    public function noGapMeansNoLongTermRequest(): void
    {
        [$client, $http] = $this->client('2026-11-03T11:59:00Z');
        $http->queue(self::HIGH, (new Psr17Factory())->createResponse(304));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertNull($result->gap);
        $this->assertSame([], $http->requestsTo(self::LONG));
    }

    #[Test]
    public function staleStateIsPrimedAgain(): void
    {
        [$client, $http, $store] = $this->client('2026-10-25T12:00:00Z');
        $old = AtomFixture::url('20261103113000_0_VXSE51_270000.xml');
        $latest = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::LONG, self::feed('2026-11-03T11:58:00Z', [$old], '"l1"'));
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [$latest], '"h2"'));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertTrue($result->primed);
        $this->assertSame([], $result->telegrams);
        $this->assertEquals(new Gap(new DateTimeImmutable('2026-10-25T12:00:00Z'), false), $result->gap);
        $this->assertTrue($store->isSeen($old));
        $this->assertTrue($store->isSeen($latest));
        $this->assertSame('', $http->requestsTo(self::HIGH)[0]->getHeaderLine('If-None-Match'));
    }

    /**
     * @return array{FeedClient, FakeHttpClient, InMemoryStateStore, FrozenClock}
     */
    private function client(?string $fetchedAt): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $store = new InMemoryStateStore($clock);

        if ($fetchedAt !== null) {
            $store->save(self::HIGH, new FeedState('"h1"', null, null, new DateTimeImmutable($fetchedAt)));
        }

        $http = new FakeHttpClient();

        return [new FeedClient($http, new Psr17Factory(), $store, $clock), $http, $store, $clock];
    }

    /**
     * @param list<string> $urls
     */
    private static function feed(string $updated, array $urls, string $etag): ResponseInterface
    {
        $factory = new Psr17Factory();
        $xml = AtomFixture::feed($updated, array_map(static fn(string $url): array => ['url' => $url], $urls));

        return $factory->createResponse(200)->withHeader('ETag', $etag)->withBody($factory->createStream($xml));
    }

    private static function telegram(): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse(200)->withBody($factory->createStream((string) file_get_contents(Fixtures::sample('32-35_08_05_100915_VXSE51.xml'))));
    }

    /**
     * @param list<Telegram> $telegrams
     *
     * @return list<string>
     */
    private static function ids(array $telegrams): array
    {
        return array_map(static fn(Telegram $telegram): string => $telegram->entry->id, $telegrams);
    }
}
