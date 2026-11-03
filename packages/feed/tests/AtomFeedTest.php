<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use JmaXml\Feed\AtomFeed;
use JmaXml\Feed\Exception\FeedException;
use JmaXml\Feed\FeedType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class AtomFeedTest extends TestCase
{
    #[Test]
    public function readsTheRealEqvolFeed(): void
    {
        $xml = file_get_contents(__DIR__ . '/Fixtures/eqvol.xml');
        $this->assertIsString($xml);

        $feed = AtomFeed::fromString($xml, FeedType::EarthquakeVolcano->highFrequencyUrl());

        $this->assertSame('https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml#short_1793725258', $feed->id);
        $this->assertSame('2026-11-04T02:00:58+09:00', $feed->updated->format(DATE_ATOM));
        $this->assertCount(25, $feed->entries);

        $first = $feed->entries[0];
        $this->assertSame('https://www.data.jma.go.jp/developer/xml/data/20261103170033_0_VFVO53_010000.xml', $first->id);
        $this->assertSame($first->id, $first->url);
        $this->assertSame('降灰予報（定時）', $first->title);
        $this->assertSame('気象庁', $first->author);
        $this->assertSame('2026-11-03T17:00:00+00:00', $first->updated->format(DATE_ATOM));
        $this->assertSame('VFVO53', $first->file?->dataTypeCode);
        $this->assertSame('2026-11-03T17:00:33+00:00', $first->issuedAt()->format(DATE_ATOM));

        foreach ($feed->entries as $entry) {
            $this->assertSame($entry->id, $entry->url);
        }
    }

    #[Test]
    #[DataProvider('invalidFeeds')]
    public function rejectsInvalidFeeds(string $xml): void
    {
        $url = FeedType::EarthquakeVolcano->highFrequencyUrl();
        error_clear_last();

        try {
            AtomFeed::fromString($xml, $url);
            $this->fail('FeedException was not thrown');
        } catch (FeedException $exception) {
            $this->assertSame($url, $exception->url);
        }

        $this->assertNull(error_get_last());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFeeds(): iterable
    {
        yield 'empty' => [''];
        yield 'not xml' => ['<feed'];
        yield 'doctype' => ['<?xml version="1.0"?><!DOCTYPE feed [<!ENTITY a "x">]><feed xmlns="http://www.w3.org/2005/Atom"/>'];
        yield 'not atom' => ['<?xml version="1.0"?><Report xmlns="http://xml.kishou.go.jp/jmaxml1/"/>'];
        yield 'feed without updated' => ['<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><id>f</id></feed>'];
    }

    #[Test]
    public function skipsInvalidEntriesWithAWarning(): void
    {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><id>f</id><updated>2026-11-03T12:00:00Z</updated>'
            . '<entry><id>e</id><updated>2026-11-03T12:00:00Z</updated></entry>'
            . '<entry><id>https://www.data.jma.go.jp/developer/xml/data/20261103115930_0_VXSE51_270000.xml</id><updated>2026-11-03T11:59:30Z</updated>'
            . '<link href="https://www.data.jma.go.jp/developer/xml/data/20261103115930_0_VXSE51_270000.xml"/></entry></feed>';

        $feed = AtomFeed::fromString($xml, FeedType::EarthquakeVolcano->highFrequencyUrl());

        $this->assertCount(1, $feed->entries);
        $this->assertSame('VXSE51', $feed->entries[0]->file?->dataTypeCode);
        $this->assertCount(1, $feed->warnings);
        $this->assertStringContainsString('entry 1', $feed->warnings[0]);
    }
}
