<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use JmaXml\Exception\ParseException;
use JmaXml\Feed\Exception\FeedException;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedState;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\InMemoryStateStore;
use JmaXml\Feed\Subscription;
use JmaXml\Feed\Telegram;
use JmaXml\Feed\Tests\Support\AtomFixture;
use JmaXml\Feed\Tests\Support\FakeHttpClient;
use JmaXml\Feed\Tests\Support\FakeTransportException;
use JmaXml\Feed\Tests\Support\FrozenClock;
use JmaXml\Tests\Support\Fixtures;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Polls with existing state and no gap.
 *
 * @author shimomo
 */
final class FeedClientPollTest extends TestCase
{
    private const string FEED = 'https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml';

    private const string SHINDO = '32-35_08_05_100915_VXSE51.xml';

    private const string DRILL = '32-35_01_03_240613_VXSE53.xml';

    #[Test]
    public function returnsNewTelegramsOldestFirst(): void
    {
        [$client, $http, $store] = $this->client();
        $a = AtomFixture::url('20261103115000_0_VXSE51_270000.xml');
        $b = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $c = AtomFixture::url('20261103115810_0_VXSE51_270000.xml');
        $store->markSeen($a, new DateTimeImmutable('2026-11-10T00:00:00Z'));

        $http->queue(self::FEED, self::feed([$a, $b, $c], etag: '"e2"'));
        $http->queue($b, self::telegram(self::SHINDO));
        $http->queue($c, self::telegram(self::SHINDO));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([$c, $b], array_map(static fn(Telegram $t): string => $t->entry->id, $result->telegrams));
        $this->assertSame('震度速報', $result->telegrams[0]->envelope->control->title);
        $this->assertSame('20100705065511', $result->telegrams[0]->report()->head->eventID);
        $this->assertFalse($result->notModified);
        $this->assertFalse($result->primed);
        $this->assertNull($result->gap);
        $this->assertSame([], $result->failures);
        $this->assertTrue($store->isSeen($b));
        $this->assertTrue($store->isSeen($c));
        $this->assertSame([], $http->requestsTo($a));

        $feedRequest = $http->requestsTo(self::FEED)[0];
        $this->assertSame('"e1"', $feedRequest->getHeaderLine('If-None-Match'));
        $this->assertSame('Tue, 03 Nov 2026 11:58:00 GMT', $feedRequest->getHeaderLine('If-Modified-Since'));

        foreach ($http->requests() as $request) {
            $this->assertSame('jmaxml-feed/0.1', $request->getHeaderLine('User-Agent'));
        }

        $state = $store->load(self::FEED);
        $this->assertNotNull($state);
        $this->assertSame('2026-11-03T12:00:00+00:00', $state->fetchedAt->format(DATE_ATOM));
        $this->assertSame('"e2"', $state->etag);
    }

    #[Test]
    public function notModifiedReturnsNothingAndKeepsValidators(): void
    {
        [$client, $http, $store] = $this->client();
        $http->queue(self::FEED, (new Psr17Factory())->createResponse(304));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertTrue($result->notModified);
        $this->assertSame([], $result->telegrams);
        $state = $store->load(self::FEED);
        $this->assertNotNull($state);
        $this->assertSame('"e1"', $state->etag);
        $this->assertSame('Tue, 03 Nov 2026 11:58:00 GMT', $state->lastModified);
        $this->assertSame('2026-11-03T12:00:00+00:00', $state->fetchedAt->format(DATE_ATOM));
    }

    #[Test]
    public function filtersByDataTypeBeforeDownloading(): void
    {
        [$client, $http, $store] = $this->client();
        $shindo = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::FEED, self::feed([$shindo]));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano, ['VXSE53']));

        $this->assertSame([], $result->telegrams);
        $this->assertSame([], $http->requestsTo($shindo));
        $this->assertTrue($store->isSeen($shindo));
    }

    #[Test]
    public function skipsDrillsByDefault(): void
    {
        [$client, $http, $store] = $this->client();
        $drill = AtomFixture::url('20261103115930_0_VXSE53_010000.xml');
        $http->queue(self::FEED, self::feed([$drill]));
        $http->queue($drill, self::telegram(self::DRILL));

        $this->assertSame([], $client->poll(new Subscription(FeedType::EarthquakeVolcano))->telegrams);
        $this->assertTrue($store->isSeen($drill));

        [$client, $http] = $this->client();
        $http->queue(self::FEED, self::feed([$drill]));
        $http->queue($drill, self::telegram(self::DRILL));

        $this->assertCount(1, $client->poll(new Subscription(FeedType::EarthquakeVolcano, [], ['通常', '訓練']))->telegrams);
    }

    #[Test]
    public function transientDownloadFailureIsRetriedNextPoll(): void
    {
        [$client, $http, $store, $clock] = $this->client();
        $shindo = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $http->queue(self::FEED, self::feed([$shindo]), (new Psr17Factory())->createResponse(304));
        $http->queue($shindo, (new Psr17Factory())->createResponse(503), self::telegram(self::SHINDO));

        $first = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([], $first->telegrams);
        $this->assertCount(1, $first->failures);
        $this->assertTrue($first->failures[0]->willRetry);
        $this->assertFalse($store->isSeen($shindo));
        $this->assertSame([$shindo], $store->load(self::FEED)?->retryUrls);

        $clock->advance('+1 minute');
        $second = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertTrue($second->notModified);
        $this->assertSame([$shindo], array_map(static fn(Telegram $t): string => $t->entry->id, $second->telegrams));
        $this->assertSame([], $store->load(self::FEED)?->retryUrls);
        $this->assertTrue($store->isSeen($shindo));
    }

    #[Test]
    public function refusesTelegramUrlsOutsideJma(): void
    {
        [$client, $http, $store] = $this->client();
        $foreign = 'https://example.com/20261103115900_0_VXSE51_270000.xml';
        $http->queue(self::FEED, self::feed([$foreign]));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertSame([], $http->requestsTo($foreign));
        $this->assertCount(1, $result->failures);
        $this->assertFalse($result->failures[0]->willRetry);
        $this->assertTrue($store->isSeen($foreign));
    }

    #[Test]
    public function unreadableTelegramIsRetriedForTenMinutes(): void
    {
        [$client, $http, $store, $clock] = $this->client();
        $broken = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $factory = new Psr17Factory();
        $http->queue(self::FEED, self::feed([$broken]), $factory->createResponse(304), $factory->createResponse(304));
        $http->queue($broken, self::notXml(), self::notXml(), self::notXml());

        $first = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertCount(1, $first->failures);
        $this->assertTrue($first->failures[0]->willRetry);
        $this->assertInstanceOf(ParseException::class, $first->failures[0]->previous);
        $this->assertFalse($store->isSeen($broken));
        $this->assertSame([$broken], $store->load(self::FEED)?->retryUrls);
        $this->assertEquals([$broken => new DateTimeImmutable('2026-11-03T12:00:00Z')], $store->load(self::FEED)?->failingSince);

        $clock->advance('+9 minutes');
        $second = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertTrue($second->failures[0]->willRetry);
        $this->assertFalse($store->isSeen($broken));

        $clock->advance('+1 minute');
        $third = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertCount(1, $third->failures);
        $this->assertFalse($third->failures[0]->willRetry);
        $this->assertInstanceOf(ParseException::class, $third->failures[0]->previous);
        $this->assertTrue($store->isSeen($broken));
        $this->assertSame([], $store->load(self::FEED)?->retryUrls);
        $this->assertSame([], $store->load(self::FEED)?->failingSince);
    }

    #[Test]
    public function missingTelegramIsRetriedUntilItAppears(): void
    {
        $factory = new Psr17Factory();

        // The window counts from the first failure, not from the telegram's age: an old telegram downloaded while
        // catching up after a gap gets the same chance.
        foreach ([[403, '20261103115930'], [404, '20261103115930'], [403, '20261103090000']] as [$status, $createdAt]) {
            [$client, $http, $store, $clock] = $this->client();
            $url = AtomFixture::url($createdAt . '_0_VXSE51_270000.xml');
            $http->queue(self::FEED, self::feed([$url]), $factory->createResponse(304));
            $http->queue($url, $factory->createResponse($status), self::telegram(self::SHINDO));

            $first = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

            $this->assertTrue($first->failures[0]->willRetry, 'HTTP ' . $status);
            $this->assertFalse($store->isSeen($url), 'HTTP ' . $status);

            $clock->advance('+1 minute');
            $second = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

            $this->assertCount(1, $second->telegrams, 'HTTP ' . $status);
            $this->assertSame([], $second->failures, 'HTTP ' . $status);
            $this->assertTrue($store->isSeen($url), 'HTTP ' . $status);
            $this->assertSame([], $store->load(self::FEED)?->failingSince, 'HTTP ' . $status);
        }
    }

    #[Test]
    public function missingTelegramIsGivenUpAfterTenMinutes(): void
    {
        $factory = new Psr17Factory();

        foreach ([403 => false, 404 => false, 410 => false, 408 => true, 429 => true, 503 => true] as $status => $stillRetried) {
            [$client, $http, $store, $clock] = $this->client();
            $url = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
            $http->queue(self::FEED, self::feed([$url]), $factory->createResponse(304));
            $http->queue($url, $factory->createResponse($status), $factory->createResponse($status));

            $this->assertTrue($client->poll(new Subscription(FeedType::EarthquakeVolcano))->failures[0]->willRetry, 'HTTP ' . $status);

            $clock->advance('+10 minutes');
            $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

            $this->assertSame($stillRetried, $result->failures[0]->willRetry, 'HTTP ' . $status);
            $this->assertSame(!$stillRetried, $store->isSeen($url), 'HTTP ' . $status);
            $this->assertSame($stillRetried ? [$url] : [], $store->load(self::FEED)?->retryUrls, 'HTTP ' . $status);
            $this->assertSame([], $store->load(self::FEED)?->failingSince, 'HTTP ' . $status);
        }
    }

    #[Test]
    public function identityAndDownloadUseTheLink(): void
    {
        [$client, $http, $store] = $this->client();
        $url = AtomFixture::url('20261103115930_0_VXSE51_270000.xml');
        $factory = new Psr17Factory();
        $xml = AtomFixture::feed('2026-11-03T11:59:58Z', [['url' => $url, 'id' => 'tag:jma,2026:abc']]);
        $http->queue(self::FEED, $factory->createResponse(200)->withBody($factory->createStream($xml)));
        $http->queue($url, self::telegram(self::SHINDO));

        $result = $client->poll(new Subscription(FeedType::EarthquakeVolcano));

        $this->assertCount(1, $result->telegrams);
        $this->assertTrue($store->isSeen($url));
        $this->assertFalse($store->isSeen('tag:jma,2026:abc'));
    }

    #[Test]
    public function feedErrorThrowsAndKeepsState(): void
    {
        [$client, $http, $store] = $this->client();
        $before = $store->load(self::FEED);
        $http->queue(self::FEED, (new Psr17Factory())->createResponse(503), new FakeTransportException('timeout'));

        try {
            $client->poll(new Subscription(FeedType::EarthquakeVolcano));
            $this->fail('FeedException was not thrown');
        } catch (FeedException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame(self::FEED, $exception->url);
        }

        $this->assertSame($before, $store->load(self::FEED));

        try {
            $client->poll(new Subscription(FeedType::EarthquakeVolcano));
            $this->fail('FeedException was not thrown');
        } catch (FeedException $exception) {
            $this->assertNull($exception->status);
        }
    }

    /**
     * @return array{FeedClient, FakeHttpClient, InMemoryStateStore, FrozenClock}
     */
    private function client(): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T12:00:00Z'));
        $store = new InMemoryStateStore($clock);
        $store->save(self::FEED, new FeedState(
            '"e1"',
            'Tue, 03 Nov 2026 11:58:00 GMT',
            new DateTimeImmutable('2026-11-03T11:58:00Z'),
            new DateTimeImmutable('2026-11-03T11:59:00Z'),
        ));
        $http = new FakeHttpClient();

        return [new FeedClient($http, new Psr17Factory(), $store, $clock), $http, $store, $clock];
    }

    /**
     * @param list<string> $urls
     */
    private static function feed(array $urls, string $etag = '"e2"'): ResponseInterface
    {
        $factory = new Psr17Factory();
        $xml = AtomFixture::feed('2026-11-03T11:59:58Z', array_map(static fn(string $url): array => ['url' => $url], $urls));

        return $factory->createResponse(200)
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', 'Tue, 03 Nov 2026 11:59:58 GMT')
            ->withBody($factory->createStream($xml));
    }

    private static function telegram(string $sample): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse(200)->withBody($factory->createStream((string) file_get_contents(Fixtures::sample($sample))));
    }

    private static function notXml(): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse(200)->withBody($factory->createStream('not xml'));
    }
}
