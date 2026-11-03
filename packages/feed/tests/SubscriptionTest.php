<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use DateTimeImmutable;
use JmaXml\Feed\Entry;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\Subscription;
use JmaXml\Feed\TelegramFileName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class SubscriptionTest extends TestCase
{
    #[Test]
    public function filtersByDataTypeCodeAndStatus(): void
    {
        $vxse53 = self::entry('https://www.data.jma.go.jp/developer/xml/data/20261103054436_0_VXSE53_010000.xml');
        $vfvo53 = self::entry('https://www.data.jma.go.jp/developer/xml/data/20261103170033_0_VFVO53_010000.xml');
        $unknown = self::entry('https://www.data.jma.go.jp/developer/xml/data/unknown.xml');

        $all = new Subscription(FeedType::EarthquakeVolcano);
        $this->assertTrue($all->wantsDataType($vxse53));
        $this->assertTrue($all->wantsDataType($unknown));
        $this->assertTrue($all->wantsStatus('通常'));
        $this->assertFalse($all->wantsStatus('訓練'));
        $this->assertFalse($all->wantsStatus('試験'));

        $quakes = new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE53']);
        $this->assertTrue($quakes->wantsDataType($vxse53));
        $this->assertFalse($quakes->wantsDataType($vfvo53));
        $this->assertFalse($quakes->wantsDataType($unknown));

        $this->assertTrue((new Subscription(FeedType::EarthquakeVolcano, [], ['通常', '訓練']))->wantsStatus('訓練'));
    }

    private static function entry(string $url): Entry
    {
        return new Entry($url, '', new DateTimeImmutable('2026-11-03T12:00:00Z'), '', $url, '', TelegramFileName::fromUrl($url));
    }
}
