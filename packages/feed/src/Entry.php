<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;

/**
 * One entry of a JMA Atom feed. Its id and link are both the telegram URL.
 *
 * @author shimomo
 */
final readonly class Entry
{
    public function __construct(
        public string $id,
        public string $title,
        public DateTimeImmutable $updated,
        public string $author,
        public string $url,
        public string $content,
        public ?TelegramFileName $file,
    ) {
        //
    }

    /**
     * When the telegram was created: the date-time in its file name, or the entry's updated time.
     */
    public function issuedAt(): DateTimeImmutable
    {
        return $this->file?->createdAt ?? $this->updated;
    }
}
