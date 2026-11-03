<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Schema;

/**
 * A namespace-qualified name of a schema component.
 *
 * @author shimomo
 */
final readonly class QName
{
    public const string XS = 'http://www.w3.org/2001/XMLSchema';

    public function __construct(
        public string $namespace,
        public string $localName,
    ) {
        //
    }

    public function equals(self $other): bool
    {
        return $this->namespace === $other->namespace && $this->localName === $other->localName;
    }

    public function key(): string
    {
        return '{' . $this->namespace . '}' . $this->localName;
    }
}
