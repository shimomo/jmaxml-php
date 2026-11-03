<?php

declare(strict_types=1);

namespace JmaXml\Feed;

/**
 * The four public JMA XML feeds. Each has a high-frequency version (updated every minute, at least the last
 * 10 minutes) and a long-term version (updated every hour, about the last 7 days).
 *
 * @author shimomo
 */
enum FeedType: string
{
    case Regular = 'regular';
    case Extra = 'extra';
    case EarthquakeVolcano = 'eqvol';
    case Other = 'other';

    private const string BASE_URL = 'https://www.data.jma.go.jp/developer/xml/feed/';

    public function highFrequencyUrl(): string
    {
        return self::BASE_URL . $this->value . '.xml';
    }

    public function longTermUrl(): string
    {
        return self::BASE_URL . $this->value . '_l.xml';
    }
}
