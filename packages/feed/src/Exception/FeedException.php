<?php

declare(strict_types=1);

namespace JmaXml\Feed\Exception;

use RuntimeException;
use Throwable;

/**
 * A feed could not be fetched or read. The poll that threw it changed no state.
 *
 * @author shimomo
 */
final class FeedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $url, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message . ' (' . $url . ($status !== null ? ', HTTP ' . $status : '') . ')', 0, $previous);
    }
}
