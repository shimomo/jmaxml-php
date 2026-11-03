<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests\Support;

use JmaXml\Feed\TelegramFileName;

/**
 * Builds Atom feeds shaped like JMA's.
 *
 * @author shimomo
 */
final class AtomFixture
{
    public const string DATA_URL = 'https://www.data.jma.go.jp/developer/xml/data/';

    /**
     * @param list<array{url: string, title?: string, updated?: string}> $entries
     */
    public static function feed(string $updated, array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" lang="ja">'
            . '<title>fixture</title><subtitle>JMAXML publishing feed</subtitle>'
            . '<updated>' . $updated . '</updated>'
            . '<id>https://www.data.jma.go.jp/developer/xml/feed/fixture.xml</id>';

        foreach ($entries as $entry) {
            $entryUpdated = $entry['updated'] ?? (TelegramFileName::fromUrl($entry['url'])?->createdAt->format('Y-m-d\TH:i:s\Z') ?? $updated);
            $url = htmlspecialchars($entry['url'], ENT_XML1);

            $xml .= '<entry>'
                . '<title>' . htmlspecialchars($entry['title'] ?? '震度速報', ENT_XML1) . '</title>'
                . '<id>' . $url . '</id>'
                . '<updated>' . $entryUpdated . '</updated>'
                . '<author><name>気象庁</name></author>'
                . '<link type="application/xml" href="' . $url . '"/>'
                . '<content type="text">fixture</content>'
                . '</entry>';
        }

        return $xml . '</feed>';
    }

    public static function url(string $fileName): string
    {
        return self::DATA_URL . $fileName;
    }
}
