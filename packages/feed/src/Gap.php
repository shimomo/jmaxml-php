<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use DateTimeImmutable;

/**
 * A period in which telegrams may have been missed because the feed was not fetched in time.
 *
 * covered is true once the long-term feed has been read far enough to cover the whole period.
 * While it is false, telegrams issued since $since may still be missing.
 *
 * @author shimomo
 */
final readonly class Gap
{
    public function __construct(
        public DateTimeImmutable $since,
        public bool $covered,
    ) {
        //
    }
}
