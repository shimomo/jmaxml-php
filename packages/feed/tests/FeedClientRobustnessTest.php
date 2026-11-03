<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedState;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\Gap;
use JmaXml\Feed\InMemoryStateStore;
use JmaXml\Feed\Subscription;
use JmaXml\Feed\Telegram;
use JmaXml\Feed\Tests\Support\AtomFixture;
use JmaXml\Feed\Tests\Support\FailingOnceStateStore;
use JmaXml\Feed\Tests\Support\FakeHttpClient;
use JmaXml\Feed\Tests\Support\FrozenClock;
use JmaXml\Feed\Tests\Support\ThrowingStream;
use JmaXml\Tests\Support\Fixtures;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Failure modes found in review: nothing may be marked seen unless it is returned, and nothing may block fresh telegrams.
 *
 * @author shimomo
 */
final class FeedClientRobustnessTest extends TestCase
{
    private const string HIGH = 'https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml';

    private const string LONG = 'https://www.data.jma.go.jp/developer/xml/feed/eqvol_l.xml';

    #[Test]
    public function bodyReadFailureIsRetriedAndEarlierTelegramsAreKept(): void
    {
        [$client, $http, $store] = $this->client('2026-11-03T11:59:00Z');
        $older = AtomFixture::url('20261103115810_0_VXSE51_270000.xml');
        $newer = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [$older, $newer]));
        $http->queue($older, self::telegram());
        $http->queue($newer, (new Psr17Factory())->createResponse(200)->withBody(new ThrowingStream()));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([$older], self::ids($result->telegrams));
        $this->assertCount(1, $result->failures);
        $this->assertTrue($result->failures[0]->willRetry);
        $this->assertTrue($store->isSeen($older));
        $this->assertFalse($store->isSeen($newer));
        $this->assertSame([$newer], $store->load(self::HIGH)?->retryUrls);
    }

    #[Test]
    public function storeFailureDuringAGapPollLosesNothing(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $inner = new InMemoryStateStore($clock);
        $inner->save(self::HIGH, new FeedState('"h1"', null, new DateTimeImmutable('2026-11-03T11:51:00Z'), new DateTimeImmutable('2026-11-03T11:51:00Z')));
        $http = new FakeHttpClient();
        $client = new FeedClient($http, new Psr17Factory(), new FailingOnceStateStore($inner), $clock);
        $missed = AtomFixture::url('20261103115500_0_VXSE51_270000.xml');
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', []), self::feed('2026-11-03T11:59:58Z', []));
        $http->queue(self::LONG, self::feed('2026-11-03T11:59:00Z', [$missed], '"l1"'), self::feed('2026-11-03T11:59:00Z', [$missed], '"l1"'));
        $http->queue($missed, self::telegram(), self::telegram());

        try {
            $client->poll(new Subscription(FeedType::EarthquakeVolcano));
            $this->fail('The store failure was swallowed');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database unavailable', $exception->getMessage());
        }

        $this->assertNull($inner->load(self::LONG));
        $this->assertFalse($inner->isSeen($missed));

        $retry = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame('', $http->requestsTo(self::LONG)[1]->getHeaderLine('If-None-Match'));
        $this->assertSame([$missed], self::ids($retry->telegrams));
        $this->assertEquals(new Gap(new DateTimeImmutable('2026-11-03T11:51:00Z'), true), $retry->gap);
    }

    #[Test]
    public function longTermFeedFailureStillDeliversFreshTelegrams(): void
    {
        [$client, $http, $store] = $this->client('2026-11-03T11:40:00Z');
        $fresh = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [$fresh]));
        $http->queue(self::LONG, (new Psr17Factory())->createResponse(503));
        $http->queue($fresh, self::telegram());

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([$fresh], self::ids($result->telegrams));
        $this->assertEquals(new Gap(new DateTimeImmutable('2026-11-03T11:40:00Z'), false), $result->gap);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('503', $result->warnings[0]);
        $this->assertEquals(new DateTimeImmutable('2026-11-03T11:40:00Z'), $store->load(self::HIGH)?->gapSince);
    }

    #[Test]
    public function stopLongerThanTheCatchUpLimitIsPrimed(): void
    {
        [$client, $http] = $this->client('2026-10-27T00:00:00Z');
        $old = AtomFixture::url('20261027060000_0_VXSE51_270000.xml');
        $http->queue(self::LONG, self::feed('2026-11-03T11:58:00Z', [$old], '"l1"'));
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', []));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertTrue($result->primed);
        $this->assertSame([], $result->telegrams);
        $this->assertEquals(new Gap(new DateTimeImmutable('2026-10-27T00:00:00Z'), false), $result->gap);
    }

    #[Test]
    public function aStoreUsedByAnotherSubscriptionIsRejected(): void
    {
        [$client, $http] = $this->client(null);
        $http->queue(self::LONG, self::feed('2026-11-03T11:58:00Z', [], '"l1"'));
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', []), (new Psr17Factory())->createResponse(304));
        $client->poll(new Subscription(FeedType::EarthquakeVolcano, ['VFVO53']));

        try {
            $client->poll(new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE52', 'VXSE53']));
            $this->fail('LogicException was not thrown');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('another Subscription', $exception->getMessage());
        }
    }

    #[Test]
    public function sameSubscriptionWithCodesInAnotherOrderIsAccepted(): void
    {
        [$client, $http] = $this->client(null);
        $http->queue(self::LONG, self::feed('2026-11-03T11:58:00Z', [], '"l1"'));
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', []), (new Psr17Factory())->createResponse(304));
        $client->poll(new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE53']));

        $this->assertTrue($client->poll(new Subscription(FeedType::EarthquakeVolcano, ['VXSE53', 'VXSE51']))->notModified);
    }

    #[Test]
    public function aQuietFeedAnsweringNotModifiedIsNoGap(): void
    {
        [$client, $http] = $this->client('2026-11-03T11:40:00Z');
        $http->queue(self::HIGH, (new Psr17Factory())->createResponse(304));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertNull($result->gap);
        $this->assertSame([], $http->requestsTo(self::LONG));
    }

    #[Test]
    public function gapsAreJudgedByTheFeedsOwnTimestamps(): void
    {
        // Polled at 11:51, the feed changed once at 11:52, and we poll again at 12:00: the feed still holds
        // at least ten minutes before 11:52, so nothing can have been missed.
        [$client, $http] = $this->client('2026-11-03T11:51:00Z');
        $http->queue(self::HIGH, self::feed('2026-11-03T11:52:00Z', []));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertNull($result->gap);
        $this->assertSame([], $http->requestsTo(self::LONG));
    }

    #[Test]
    public function unrecognisedFileNamesAreReportedWhenFilteringByCode(): void
    {
        [$client, $http, $store] = $this->client('2026-11-03T11:59:00Z');
        $odd = AtomFixture::url('VXSE53-renamed.xml');
        $http->queue(self::HIGH, self::feed('2026-11-03T11:59:58Z', [$odd]));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano, ['VXSE53']));

        $this->assertCount(1, $result->failures);
        $this->assertFalse($result->failures[0]->willRetry);
        $this->assertSame([], $http->requestsTo($odd));
        $this->assertTrue($store->isSeen($odd));
    }

    #[Test]
    public function unsafeSettingsAreRejected(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $rejected = 0;

        foreach ([[600, 518400, 691200], [0, 518400, 691200], [480, 604800, 691200], [480, 518400, 86400]] as [$gap, $catchUp, $retention]) {
            try {
                new FeedClient(new FakeHttpClient(), new Psr17Factory(), new InMemoryStateStore($clock), $clock, FeedClient::DEFAULT_USER_AGENT, $gap, $catchUp, $retention);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(4, $rejected);
    }

    /**
     * @return array{FeedClient, FakeHttpClient, InMemoryStateStore}
     */
    private function client(?string $fetchedAt): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $store = new InMemoryStateStore($clock);

        if ($fetchedAt !== null) {
            $store->save(self::HIGH, new FeedState('"h1"', null, null, new DateTimeImmutable($fetchedAt)));
        }

        $http = new FakeHttpClient();

        return [new FeedClient($http, new Psr17Factory(), $store, $clock), $http, $store];
    }

    /**
     * @param list<string> $urls
     */
    private static function feed(string $updated, array $urls, string $etag = '"h2"'): ResponseInterface
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
