<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\InMemoryStateStore;
use JmaXml\Feed\Subscription;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Talks to the real JMA server. Excluded by default; run with `vendor/bin/phpunit --group live`.
 *
 * @author shimomo
 */
final class LiveFeedTest extends TestCase
{
    #[Test]
    #[Group('live')]
    public function pollsTheRealEarthquakeVolcanoFeed(): void
    {
        $clock = new class () implements ClockInterface {
            #[Override]
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now');
            }
        };
        $factory = new Psr17Factory();
        $client = new FeedClient(new Psr18Client(null, $factory, $factory), $factory, new InMemoryStateStore($clock), $clock);
        $subscription = new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE52', 'VXSE53']);

        $first = $client->poll($subscription);

        $this->assertTrue($first->primed);
        $this->assertSame([], $first->telegrams);

        $second = $client->poll($subscription);

        $this->assertFalse($second->primed);
        $this->assertNull($second->gap);
        $this->assertSame([], $second->failures);
    }
}
