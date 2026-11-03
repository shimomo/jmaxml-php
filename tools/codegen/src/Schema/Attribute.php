<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Schema;

/**
 * An unqualified attribute declared on a complex type.
 *
 * @author shimomo
 */
final readonly class Attribute
{
    public function __construct(
        public string $name,
        public QName $type,
        public bool $required,
    ) {
        //
    }
}
