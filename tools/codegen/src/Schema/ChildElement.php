<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Schema;

/**
 * An element declared in a complex type's sequence (local, or a reference to a global element).
 *
 * @author shimomo
 */
final readonly class ChildElement
{
    /**
     * @param int|null $maxOccurs null means unbounded
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public QName $type,
        public int $minOccurs,
        public ?int $maxOccurs,
        public bool $nillable,
    ) {
        //
    }
}
