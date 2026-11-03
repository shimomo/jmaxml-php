<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Schema;

/**
 * A named complex type: either a sequence of child elements or simple content, plus attributes.
 *
 * @author shimomo
 */
final readonly class ComplexType
{
    /**
     * @param list<ChildElement> $elements
     * @param list<Attribute> $attributes
     * @param string|null $anyNamespace the namespace attribute of an xs:any wildcard, if the sequence has one
     */
    public function __construct(
        public string $namespace,
        public string $prefix,
        public string $name,
        public ?QName $simpleContentBase,
        public array $elements,
        public array $attributes,
        public ?string $anyNamespace,
    ) {
        //
    }

    public function qname(): QName
    {
        return new QName($this->namespace, $this->name);
    }
}
