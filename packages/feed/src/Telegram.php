<?php

declare(strict_types=1);

namespace JmaXml\Feed;

use JmaXml\Envelope;
use JmaXml\Report;

/**
 * A new telegram returned by a poll: its feed entry, the XML as downloaded, and its control and header parts.
 *
 * @author shimomo
 */
final readonly class Telegram
{
    public function __construct(
        public Entry $entry,
        public string $xml,
        public Envelope $envelope,
    ) {
        //
    }

    /**
     * Reads the whole telegram, body included. Parses again on every call.
     */
    public function report(): Report
    {
        return Report::fromString($this->xml);
    }
}
