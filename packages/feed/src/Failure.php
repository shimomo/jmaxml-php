<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use Throwable;

/**
 * A telegram that could not be returned. When willRetry is true it stays unseen and the next poll tries it again.
 *
 * @author shimomo
 */
final readonly class Failure
{
    public function __construct(
        public Entry $entry,
        public string $reason,
        public bool $willRetry,
        public ?Throwable $previous = null,
    ) {
        //
    }
}
