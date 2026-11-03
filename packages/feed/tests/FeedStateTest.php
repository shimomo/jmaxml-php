<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use JmaXml\Feed\FeedState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class FeedStateTest extends TestCase
{
    #[Test]
    public function roundTripsThroughArrays(): void
    {
        $full = new FeedState(
            '"e1"',
            'Tue, 03 Nov 2026 11:58:00 GMT',
            new DateTimeImmutable('2026-11-03T20:58:00+09:00'),
            new DateTimeImmutable('2026-11-03T12:00:00Z'),
            new DateTimeImmutable('2026-11-03T11:20:00Z'),
            ['https://www.data.jma.go.jp/developer/xml/data/a.xml', 'https://www.data.jma.go.jp/developer/xml/data/b.xml'],
            '["eqvol",["VXSE51"],["通常"]]',
        );
        $empty = new FeedState(null, null, null, new DateTimeImmutable('2026-11-03T12:00:00Z'));

        $this->assertEquals($full, FeedState::fromArray($full->toArray()));
        $this->assertEquals($empty, FeedState::fromArray($empty->toArray()));
        $this->assertSame('2026-11-03T12:00:00+00:00', $full->toArray()['fetchedAt']);
        $this->assertSame([], $empty->toArray()['retryUrls']);
    }

    #[Test]
    public function rejectsMalformedArrays(): void
    {
        $rejected = 0;

        foreach ([['fetchedAt' => 'yesterday'], []] as $data) {
            try {
                FeedState::fromArray($data);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(2, $rejected);
    }
}
