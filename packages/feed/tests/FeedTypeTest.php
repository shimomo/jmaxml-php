<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use JmaXml\Feed\FeedType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class FeedTypeTest extends TestCase
{
    #[Test]
    public function buildsFeedUrls(): void
    {
        $this->assertSame('https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml', FeedType::EarthquakeVolcano->highFrequencyUrl());
        $this->assertSame('https://www.data.jma.go.jp/developer/xml/feed/extra_l.xml', FeedType::Extra->longTermUrl());
    }
}
