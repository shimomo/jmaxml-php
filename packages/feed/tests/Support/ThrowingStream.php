<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response body that fails when read, like a lazy stream whose connection drops mid-body.
 *
 * @author shimomo
 */
final class ThrowingStream implements StreamInterface
{
    #[Override]
    public function __toString(): string
    {
        throw new RuntimeException('Connection reset while reading the body');
    }

    #[Override]
    public function close(): void
    {
    }

    #[Override]
    public function detach()
    {
        return null;
    }

    #[Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[Override]
    public function tell(): int
    {
        return 0;
    }

    #[Override]
    public function eof(): bool
    {
        return false;
    }

    #[Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable');
    }

    #[Override]
    public function rewind(): void
    {
        throw new RuntimeException('Not seekable');
    }

    #[Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[Override]
    public function write(string $string): int
    {
        throw new RuntimeException('Not writable');
    }

    #[Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[Override]
    public function read(int $length): string
    {
        throw new RuntimeException('Connection reset while reading the body');
    }

    #[Override]
    public function getContents(): string
    {
        throw new RuntimeException('Connection reset while reading the body');
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
