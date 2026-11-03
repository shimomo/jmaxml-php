<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * @author shimomo
 */
final class FakeTransportException extends RuntimeException implements ClientExceptionInterface
{
}
