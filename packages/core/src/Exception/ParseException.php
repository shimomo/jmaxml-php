<?php

declare(strict_types=1);

namespace JmaXml\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a telegram cannot be read into the typed model.
 *
 * The path points at the offending node, e.g. "/Report/Head/Headline/Information[2]/Item".
 *
 * @author shimomo
 */
final class ParseException extends RuntimeException
{
    public function __construct(string $reason, public readonly string $path, ?Throwable $previous = null)
    {
        parent::__construct($reason . ' at ' . $path, 0, $previous);
    }
}
