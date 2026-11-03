<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The parts of a telegram file name, e.g. 20261103054436_0_VXSE53_010000.xml.
 *
 * The leading date-time is the telegram's Control/DateTime in UTC.
 *
 * @author shimomo
 */
final readonly class TelegramFileName
{
    private const string PATTERN = '/(\d{14})_(\d+)_([A-Z0-9]+)_(\d{6})\.xml$/';

    public function __construct(
        public DateTimeImmutable $createdAt,
        public string $sequence,
        public string $dataTypeCode,
        public string $officeCode,
    ) {
        //
    }

    public static function fromUrl(string $url): ?self
    {
        if (preg_match(self::PATTERN, $url, $matches) !== 1) {
            return null;
        }

        $createdAt = DateTimeImmutable::createFromFormat('!YmdHis', $matches[1], new DateTimeZone('UTC'));

        if ($createdAt === false || $createdAt->format('YmdHis') !== $matches[1]) {
            return null;
        }

        return new self($createdAt, $matches[2], $matches[3], $matches[4]);
    }
}
