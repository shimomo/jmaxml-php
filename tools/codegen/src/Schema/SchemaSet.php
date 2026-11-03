<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Schema;

use DOMDocument;
use DOMElement;
use LogicException;

/**
 * The JMA XML schema, loaded from an entry XSD and everything it imports or includes.
 *
 * Only the constructs the official schema uses are supported: named complex types holding one
 * sequence (local elements, element references and at most one xs:any) or simple content,
 * unqualified attributes, and simple types built by restriction, list or union. Anything else
 * stops loading with a LogicException instead of being silently ignored.
 *
 * @author shimomo
 */
final class SchemaSet
{
    /**
     * @param array<string, ComplexType> $complexTypes keyed by QName::key()
     * @param array<string, array{DOMElement, DOMElement}> $simpleTypes keyed by QName::key(); [simpleType, schema root]
     * @param array<string, string> $prefixes target namespace => prefix
     */
    private function __construct(
        private readonly array $complexTypes,
        private readonly array $simpleTypes,
        private readonly array $prefixes,
    ) {
        //
    }

    public static function load(string $entryXsdPath): self
    {
        $roots = self::loadDocuments($entryXsdPath);

        $globalElements = [];
        $simpleTypes = [];
        $prefixes = [];

        foreach ($roots as $root) {
            $namespace = $root->getAttribute('targetNamespace');
            $prefix = $root->lookupPrefix($namespace);

            if ($prefix === null || $prefix === '') {
                throw new LogicException('No prefix is bound to target namespace ' . $namespace);
            }

            $prefixes[$namespace] = $prefix;

            foreach (self::children($root) as $child) {
                match ($child->localName) {
                    'element' => $globalElements[(new QName($namespace, $child->getAttribute('name')))->key()] = self::resolve($child, self::requiredAttribute($child, 'type')),
                    'simpleType' => $simpleTypes[(new QName($namespace, $child->getAttribute('name')))->key()] = [$child, $root],
                    default => null,
                };
            }
        }

        $complexTypes = [];

        foreach ($roots as $root) {
            foreach (self::children($root) as $child) {
                if ($child->localName === 'complexType') {
                    $type = self::complexTypeFrom($child, $root, $prefixes, $globalElements);
                    $complexTypes[$type->qname()->key()] = $type;
                }
            }
        }

        uasort($complexTypes, static fn(ComplexType $a, ComplexType $b): int => [$a->namespace, $a->name] <=> [$b->namespace, $b->name]);

        return new self($complexTypes, $simpleTypes, $prefixes);
    }

    /**
     * @return list<ComplexType>
     */
    public function complexTypes(): array
    {
        return array_values($this->complexTypes);
    }

    public function complexType(QName $name): ComplexType
    {
        return $this->complexTypes[$name->key()] ?? throw new LogicException('Unknown complex type ' . $name->key());
    }

    public function isComplexType(QName $name): bool
    {
        return isset($this->complexTypes[$name->key()]);
    }

    /**
     * Resolves a simple type down to a built-in type.
     *
     * jmx_eb:nullablefloat and jmx_eb:nullableinteger are returned as they are, list types become
     * {XS}list, and other unions (the control Status) become {XS}string.
     */
    public function simpleTypeBase(QName $name): QName
    {
        if ($name->namespace === QName::XS) {
            return $name;
        }

        if (in_array($name->localName, ['nullablefloat', 'nullableinteger'], true)) {
            return $name;
        }

        [$simpleType] = $this->simpleTypes[$name->key()] ?? throw new LogicException('Unknown simple type ' . $name->key());
        $definition = self::children($simpleType)[0] ?? throw new LogicException('Empty simple type ' . $name->key());

        return match ($definition->localName) {
            'restriction' => $this->simpleTypeBase(self::resolve($definition, self::requiredAttribute($definition, 'base'))),
            'list' => new QName(QName::XS, 'list'),
            'union' => new QName(QName::XS, 'string'),
            default => throw new LogicException('Unsupported simple type definition xs:' . $definition->localName),
        };
    }

    public function prefixFor(string $namespace): string
    {
        return $this->prefixes[$namespace] ?? throw new LogicException('Unknown namespace ' . $namespace);
    }

    /**
     * @return list<DOMElement> schema roots in load order
     */
    private static function loadDocuments(string $entryXsdPath): array
    {
        $queue = [$entryXsdPath];
        $seen = [];
        $roots = [];

        while ($queue !== []) {
            $path = array_shift($queue);
            $real = realpath($path);

            if ($real === false) {
                throw new LogicException('Cannot find schema ' . $path);
            }

            if (isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;
            $document = new DOMDocument();

            if (!$document->load($real, LIBXML_NONET)) {
                throw new LogicException('Cannot parse schema ' . $real);
            }

            $root = $document->documentElement;

            if (!$root instanceof DOMElement || $root->namespaceURI !== QName::XS || $root->localName !== 'schema') {
                throw new LogicException('Not an XML schema: ' . $real);
            }

            if ($root->getAttribute('elementFormDefault') !== 'qualified') {
                throw new LogicException('Only elementFormDefault="qualified" is supported: ' . $real);
            }

            $roots[] = $root;

            foreach (self::children($root) as $child) {
                match ($child->localName) {
                    'import', 'include' => $queue[] = dirname($real) . '/' . self::requiredAttribute($child, 'schemaLocation'),
                    'annotation', 'element', 'complexType', 'simpleType' => null,
                    default => throw new LogicException('Unsupported top-level xs:' . $child->localName . ' in ' . $real),
                };
            }
        }

        return $roots;
    }

    /**
     * @param array<string, string> $prefixes
     * @param array<string, QName> $globalElements
     */
    private static function complexTypeFrom(DOMElement $definition, DOMElement $root, array $prefixes, array $globalElements): ComplexType
    {
        $namespace = $root->getAttribute('targetNamespace');
        $name = self::requiredAttribute($definition, 'name');
        $elements = [];
        $attributes = [];
        $anyNamespace = null;
        $simpleContentBase = null;

        foreach (self::children($definition) as $child) {
            switch ($child->localName) {
                case 'annotation':
                    break;
                case 'sequence':
                    if ($elements !== [] || $child->hasAttribute('minOccurs') || $child->hasAttribute('maxOccurs')) {
                        throw new LogicException('Only one plain xs:sequence is supported in ' . $name);
                    }

                    foreach (self::children($child) as $particle) {
                        match ($particle->localName) {
                            'element' => $elements[] = self::childElement($particle, $namespace, $globalElements),
                            'any' => $anyNamespace = $particle->getAttribute('namespace'),
                            'annotation' => null,
                            default => throw new LogicException('Unsupported xs:' . $particle->localName . ' in the sequence of ' . $name),
                        };
                    }

                    break;
                case 'simpleContent':
                    $extension = self::children($child)[0] ?? null;

                    if ($extension === null || $extension->localName !== 'extension') {
                        throw new LogicException('Only xs:extension is supported in the simple content of ' . $name);
                    }

                    $simpleContentBase = self::resolve($extension, self::requiredAttribute($extension, 'base'));

                    foreach (self::children($extension) as $attribute) {
                        $attributes[] = self::attribute($attribute, $name);
                    }

                    break;
                case 'attribute':
                    $attributes[] = self::attribute($child, $name);

                    break;
                default:
                    throw new LogicException('Unsupported xs:' . $child->localName . ' in ' . $name);
            }
        }

        return new ComplexType($namespace, $prefixes[$namespace], $name, $simpleContentBase, $elements, $attributes, $anyNamespace);
    }

    /**
     * @param array<string, QName> $globalElements
     */
    private static function childElement(DOMElement $element, string $namespace, array $globalElements): ChildElement
    {
        foreach (['default', 'fixed', 'substitutionGroup', 'form'] as $unsupported) {
            if ($element->hasAttribute($unsupported)) {
                throw new LogicException('Unsupported attribute ' . $unsupported . ' on xs:element');
            }
        }

        foreach (self::children($element) as $child) {
            if ($child->localName !== 'annotation') {
                throw new LogicException('Anonymous types are not supported (xs:element ' . $element->getAttribute('name') . ')');
            }
        }

        $maxOccurs = $element->hasAttribute('maxOccurs') ? $element->getAttribute('maxOccurs') : '1';
        $minOccurs = $element->hasAttribute('minOccurs') ? (int) $element->getAttribute('minOccurs') : 1;

        if ($element->hasAttribute('ref')) {
            $ref = self::resolve($element, $element->getAttribute('ref'));
            $name = $ref->localName;
            $elementNamespace = $ref->namespace;
            $type = $globalElements[$ref->key()] ?? throw new LogicException('Unknown global element ' . $ref->key());
        } else {
            $name = self::requiredAttribute($element, 'name');
            $elementNamespace = $namespace;
            $type = self::resolve($element, self::requiredAttribute($element, 'type'));
        }

        return new ChildElement(
            $name,
            $elementNamespace,
            $type,
            $minOccurs,
            $maxOccurs === 'unbounded' ? null : (int) $maxOccurs,
            $element->getAttribute('nillable') === 'true',
        );
    }

    private static function attribute(DOMElement $attribute, string $typeName): Attribute
    {
        if ($attribute->localName !== 'attribute' || $attribute->hasAttribute('ref') || $attribute->hasAttribute('default') || $attribute->hasAttribute('fixed')) {
            throw new LogicException('Unsupported attribute declaration in ' . $typeName);
        }

        return new Attribute(
            self::requiredAttribute($attribute, 'name'),
            self::resolve($attribute, self::requiredAttribute($attribute, 'type')),
            $attribute->getAttribute('use') === 'required',
        );
    }

    private static function resolve(DOMElement $context, string $qualifiedName): QName
    {
        $separator = strpos($qualifiedName, ':');
        $prefix = $separator === false ? null : substr($qualifiedName, 0, $separator);
        $localName = $separator === false ? $qualifiedName : substr($qualifiedName, $separator + 1);
        $namespace = $context->lookupNamespaceURI($prefix);

        if ($namespace === null) {
            throw new LogicException('Cannot resolve the prefix of ' . $qualifiedName);
        }

        return new QName($namespace, $localName);
    }

    private static function requiredAttribute(DOMElement $element, string $name): string
    {
        if (!$element->hasAttribute($name)) {
            throw new LogicException('Missing attribute ' . $name . ' on xs:' . $element->localName);
        }

        return $element->getAttribute($name);
    }

    /**
     * @return list<DOMElement> child elements in the XML Schema namespace
     */
    private static function children(DOMElement $parent): array
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI !== QName::XS) {
                    throw new LogicException('Unexpected element ' . $child->nodeName . ' in schema');
                }

                $children[] = $child;
            }
        }

        return $children;
    }
}
