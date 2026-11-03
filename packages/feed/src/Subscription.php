<?php

declare(strict_types=1);

namespace JmaXml\Feed;

/**
 * Which telegrams of a feed to return.
 *
 * @author shimomo
 */
final readonly class Subscription
{
    /**
     * @param list<string> $dataTypeCodes data type codes to download (e.g. ['VXSE51', 'VXSE52', 'VXSE53']); empty means all
     * @param list<string> $statuses Control/Status values to return; drills (訓練) and tests (試験) only when listed
     */
    public function __construct(
        public FeedType $feed,
        public array $dataTypeCodes = [],
        public array $statuses = ['通常'],
    ) {
        //
    }

    /**
     * Whether to download the entry's telegram. With codes listed, an entry whose file name cannot be read is not wanted.
     */
    public function wantsDataType(Entry $entry): bool
    {
        if ($this->dataTypeCodes === []) {
            return true;
        }

        return $entry->file !== null && in_array($entry->file->dataTypeCode, $this->dataTypeCodes, true);
    }

    public function wantsStatus(string $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    /**
     * Identifies the subscription regardless of the order of its codes and statuses.
     */
    public function fingerprint(): string
    {
        $codes = $this->dataTypeCodes;
        $statuses = $this->statuses;
        sort($codes, SORT_STRING);
        sort($statuses, SORT_STRING);

        return json_encode([$this->feed->value, $codes, $statuses], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
