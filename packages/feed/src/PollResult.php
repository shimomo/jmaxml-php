<?php

declare(strict_types=1);

namespace JmaXml\Feed;

/**
 * The outcome of one FeedClient::poll().
 *
 * @author shimomo
 */
final readonly class PollResult
{
    /**
     * @param list<Telegram> $telegrams new telegrams that match the subscription, oldest first; they are now marked seen
     * @param list<Failure> $failures
     * @param bool $notModified the high-frequency feed answered 304
     * @param bool $primed the state was (re)built: everything in the feeds was marked seen and nothing was returned
     * @param Gap|null $gap the gap handled by this poll, if any
     */
    public function __construct(
        public array $telegrams,
        public array $failures,
        public bool $notModified,
        public bool $primed,
        public ?Gap $gap,
    ) {
        //
    }
}
