<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests;

use JmaXml\Feed\TelegramFileName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class TelegramFileNameTest extends TestCase
{
    #[Test]
    public function parsesTelegramUrls(): void
    {
        $file = TelegramFileName::fromUrl('https://www.data.jma.go.jp/developer/xml/data/20261103054436_0_VXSE53_010000.xml');

        $this->assertNotNull($file);
        $this->assertSame('2026-11-03T05:44:36+00:00', $file->createdAt->format(DATE_ATOM));
        $this->assertSame('0', $file->sequence);
        $this->assertSame('VXSE53', $file->dataTypeCode);
        $this->assertSame('010000', $file->officeCode);
        $this->assertNull(TelegramFileName::fromUrl('https://example.com/feed.xml'));
        $this->assertNull(TelegramFileName::fromUrl('https://www.data.jma.go.jp/developer/xml/data/20261303054436_0_VXSE53_010000.xml'));
    }
}
