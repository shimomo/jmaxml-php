<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Php;

use JmaXml\Codegen\Dictionary\Description;
use JmaXml\Codegen\Dictionary\Dictionary;
use JmaXml\Codegen\Generator;
use JmaXml\Codegen\Schema\Attribute;
use JmaXml\Codegen\Schema\ChildElement;
use JmaXml\Codegen\Schema\ComplexType;
use JmaXml\Codegen\Schema\QName;
use JmaXml\Codegen\Schema\SchemaSet;
use LogicException;

/**
 * Renders one complex type as a final readonly PHP class with a fromElement() factory.
 *
 * @author shimomo
 */
final class ClassRenderer
{
    private const string REPORT_NAMESPACE = 'http://xml.kishou.go.jp/jmaxml1/';

    private const string ELEMENT_BASIS_NAMESPACE = 'http://xml.kishou.go.jp/jmaxml1/elementBasis1/';

    private const array BODY_NAMESPACES = [
        'http://xml.kishou.go.jp/jmaxml1/body/meteorology1/',
        'http://xml.kishou.go.jp/jmaxml1/body/seismology1/',
        'http://xml.kishou.go.jp/jmaxml1/body/volcanology1/',
    ];

    /**
     * Short names that generated files may import themselves; foreign classes with these names get an alias.
     */
    private const array FIXED_SHORT_NAMES = ['DOMElement', 'Node', 'DateTimeImmutable', 'DateInterval', 'HasPoints', 'Document', 'ParseException', 'BodyContract'];

    /**
     * @var array<string, string|null> imported class => alias (null when imported under its own short name)
     */
    private array $imports = [];

    /**
     * @param array<string, string> $namespaceMap XML namespace URI => PHP namespace
     */
    public function __construct(
        private readonly SchemaSet $schemas,
        private readonly Dictionary $dictionary,
        private readonly array $namespaceMap,
    ) {
        //
    }

    public function phpNamespace(string $xmlNamespace): string
    {
        return $this->namespaceMap[$xmlNamespace] ?? throw new LogicException('No PHP namespace for ' . $xmlNamespace);
    }

    public function render(ComplexType $type): string
    {
        $this->imports = ['DOMElement' => null, 'JmaXml\\Internal\\Node' => null];

        $namespace = $this->phpNamespace($type->namespace);
        $className = Naming::className($type->name);
        $isReport = $type->namespace === self::REPORT_NAMESPACE && $type->name === 'type.report';
        $isBody = in_array($type->namespace, self::BODY_NAMESPACES, true) && $type->name === 'type.Body';
        $hasPoints = $type->namespace === self::ELEMENT_BASIS_NAMESPACE && $type->name === 'type.Coordinate';

        $properties = $this->properties($type, $isReport);

        if ($isReport) {
            $this->imports['JmaXml\\Internal\\Document'] = null;
            $this->imports['JmaXml\\Exception\\ParseException'] = null;
        }

        if ($isBody) {
            $this->imports['JmaXml\\Body'] = 'BodyContract';
        }

        if ($hasPoints) {
            $this->imports['JmaXml\\Geo\\HasPoints'] = null;
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            Generator::MARKER,
            '',
            'namespace ' . $namespace . ';',
            '',
            ...$this->renderImports(),
            '',
            ...$this->classDocblock($type),
            'final readonly class ' . $className . ($isBody ? ' implements BodyContract' : ''),
            '{',
        ];

        if ($hasPoints) {
            $lines[] = '    use HasPoints;';
            $lines[] = '';
        }

        $lines[] = '    public const string NS = ' . self::quote($type->namespace) . ';';
        $lines[] = '';
        array_push($lines, ...$this->constructor($properties));
        $lines[] = '';

        if ($isReport) {
            array_push($lines, ...self::reportEntryPoints());
        }

        array_push($lines, ...$this->factory($properties));

        if ($isReport) {
            $lines[] = '';
            array_push($lines, ...$this->bodyDispatcher());
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<Property>
     */
    private function properties(ComplexType $type, bool $isReport): array
    {
        $properties = [];

        if ($type->simpleContentBase !== null) {
            $kind = $this->kindOf($type->simpleContentBase);
            $properties[] = new Property('value', $kind->phpType(), self::listDoc($kind->phpType(), $kind), $kind->conversion('Node::text($element)', '$path'), null);
        }

        foreach ($type->elements as $element) {
            $properties[] = $this->elementProperty($type, $element);
        }

        if ($type->anyNamespace === '##other') {
            if (!$isReport) {
                throw new LogicException('xs:any ##other is only supported in jmx:type.report, found in ' . $type->name);
            }

            $properties[] = new Property('body', 'Body', null, 'self::body($element, $path . \'/Body\')', null);
        }

        foreach ($type->attributes as $attribute) {
            $properties[] = $this->attributeProperty($type, $attribute);
        }

        $names = array_map(static fn(Property $property): string => $property->name, $properties);

        if (count($names) !== count(array_unique($names))) {
            throw new LogicException('Duplicate property names in ' . $type->name);
        }

        return $properties;
    }

    private function elementProperty(ComplexType $type, ChildElement $element): Property
    {
        $namespace = $element->namespace === $type->namespace ? 'self::NS' : self::quote($element->namespace);
        $name = self::quote($element->name);
        $path = '$path . ' . self::quote('/' . $element->name);

        if ($this->schemas->isComplexType($element->type)) {
            $class = $this->classReference($type, $element->type);
            $single = $class;
            $singleDoc = $class;
            $value = static fn(string $node, string $nodePath): string => $class . '::fromElement(' . $node . ', ' . $nodePath . ')';
            $kind = null;
        } else {
            $kind = $this->kindOf($element->type);

            if ($element->nillable) {
                if ($kind !== ScalarKind::DateTime) {
                    throw new LogicException('Only nillable xs:dateTime is supported (' . $type->name . '/' . $element->name . ')');
                }

                $single = '?DateTimeImmutable';
                $singleDoc = 'DateTimeImmutable|null';
                $value = static fn(string $node, string $nodePath): string => 'Node::toNillableDateTime(' . $node . ', ' . $nodePath . ')';
            } else {
                $single = $kind->phpType();
                $singleDoc = $kind->docType();
                $value = static fn(string $node, string $nodePath): string => $kind->conversion('Node::text(' . $node . ')', $nodePath);
            }
        }

        $closure = 'static fn(DOMElement $e, string $p): ' . $single . ' => ' . $value('$e', '$p');
        $description = $this->dictionary->forElement($type->prefix, $type->name, $element->name);
        $property = Naming::propertyName($element->name);

        if ($element->maxOccurs === 1) {
            if ($element->minOccurs >= 1) {
                return new Property($property, $single, self::listDoc($single, $kind), $value('Node::one($element, ' . $namespace . ', ' . $name . ', $path)', $path), $description);
            }

            $optional = self::nullable($single);

            return new Property(
                $property,
                $optional,
                self::listDoc($optional, $kind),
                'Node::mapOptional(Node::optional($element, ' . $namespace . ', ' . $name . '), ' . $path . ', ' . $closure . ')',
                $description,
            );
        }

        $source = $element->minOccurs >= 1
            ? 'Node::atLeastOne($element, ' . $namespace . ', ' . $name . ', $path)'
            : 'Node::many($element, ' . $namespace . ', ' . $name . ')';

        return new Property($property, 'array', 'list<' . $singleDoc . '>', 'Node::mapMany(' . $source . ', ' . $path . ', ' . $closure . ')', $description);
    }

    private function attributeProperty(ComplexType $type, Attribute $attribute): Property
    {
        $kind = $this->kindOf($attribute->type);
        $name = self::quote($attribute->name);
        $path = '$path . ' . self::quote('/@' . $attribute->name);
        $required = 'Node::requiredAttribute($element, ' . $name . ', $path)';
        $description = $this->dictionary->forAttribute($type->prefix, $type->name, $attribute->name);
        $property = Naming::propertyName($attribute->name);

        if ($attribute->required) {
            return new Property($property, $kind->phpType(), self::listDoc($kind->phpType(), $kind), $kind->conversion($required, $path), $description);
        }

        $optional = self::nullable($kind->phpType());
        $expression = 'Node::attribute($element, ' . $name . ') === null ? null : ' . $kind->conversion($required, $path);

        return new Property($property, $optional, self::listDoc($optional, $kind), $expression, $description);
    }

    private function kindOf(QName $simpleType): ScalarKind
    {
        $kind = ScalarKind::fromBase($this->schemas->simpleTypeBase($simpleType));

        foreach ($kind->imports() as $import) {
            $this->imports[$import] = null;
        }

        return $kind;
    }

    private function classReference(ComplexType $from, QName $target): string
    {
        $className = Naming::className($target->localName);

        if ($target->namespace === $from->namespace) {
            return $className;
        }

        $fqcn = $this->phpNamespace($target->namespace) . '\\' . $className;

        if (array_key_exists($fqcn, $this->imports)) {
            return $this->imports[$fqcn] ?? $className;
        }

        $taken = in_array($className, self::FIXED_SHORT_NAMES, true)
            || in_array($className, $this->classNamesIn($from->namespace), true)
            || in_array($className, $this->importedShortNames(), true);

        if ($taken) {
            $segments = explode('\\', $this->phpNamespace($target->namespace));
            $alias = end($segments) . $className;
            $this->imports[$fqcn] = $alias;

            return $alias;
        }

        $this->imports[$fqcn] = null;

        return $className;
    }

    /**
     * @return list<string>
     */
    private function classNamesIn(string $xmlNamespace): array
    {
        $names = [];

        foreach ($this->schemas->complexTypes() as $type) {
            if ($type->namespace === $xmlNamespace) {
                $names[] = Naming::className($type->name);
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function importedShortNames(): array
    {
        $names = [];

        foreach ($this->imports as $class => $alias) {
            $segments = explode('\\', $class);
            $names[] = $alias ?? end($segments);
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function renderImports(): array
    {
        $classes = array_keys($this->imports);
        usort($classes, static fn(string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        return array_map(fn(string $class): string => 'use ' . $class . ($this->imports[$class] !== null ? ' as ' . $this->imports[$class] : '') . ';', $classes);
    }

    /**
     * @return list<string>
     */
    private function classDocblock(ComplexType $type): array
    {
        $description = $this->dictionary->forType($type->prefix, $type->name);
        $paragraphs = array_values(array_filter(
            [$description?->meaning ?? '', $description?->explanation ?? ''],
            static fn(string $text): bool => $text !== '',
        ));

        if ($paragraphs === []) {
            return [];
        }

        $lines = ['/**'];

        foreach ($paragraphs as $index => $paragraph) {
            if ($index > 0) {
                $lines[] = ' *';
            }

            foreach (preg_split('/\R/u', self::escapeComment($paragraph)) ?: [] as $line) {
                $line = rtrim($line);
                $lines[] = $line === '' ? ' *' : ' * ' . $line;
            }
        }

        $lines[] = ' */';

        return $lines;
    }

    /**
     * @param list<Property> $properties
     *
     * @return list<string>
     */
    private function constructor(array $properties): array
    {
        if ($properties === []) {
            return ['    public function __construct()', '    {', '        //', '    }'];
        }

        $lines = ['    public function __construct('];

        foreach ($properties as $property) {
            $docblock = self::propertyDocblock($property);

            if ($docblock !== null) {
                $lines[] = '        ' . $docblock;
            }

            $lines[] = '        public ' . $property->type . ' $' . $property->name . ',';
        }

        return [...$lines, '    ) {', '        //', '    }'];
    }

    /**
     * @param list<Property> $properties
     *
     * @return list<string>
     */
    private function factory(array $properties): array
    {
        $lines = ['    public static function fromElement(DOMElement $element, string $path): self', '    {'];

        if ($properties === []) {
            return [...$lines, '        return new self();', '    }'];
        }

        $lines[] = '        return new self(';

        foreach ($properties as $property) {
            $lines[] = '            ' . $property->name . ': ' . $property->expression . ',';
        }

        return [...$lines, '        );', '    }'];
    }

    /**
     * @return list<string>
     */
    private static function reportEntryPoints(): array
    {
        return [
            '    public static function fromString(string $xml): self',
            '    {',
            '        return self::fromElement(Document::fromString($xml), \'/Report\');',
            '    }',
            '',
            '    public static function fromFile(string $path): self',
            '    {',
            '        return self::fromElement(Document::fromFile($path), \'/Report\');',
            '    }',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function bodyDispatcher(): array
    {
        return [
            '    private static function body(DOMElement $element, string $path): Body',
            '    {',
            '        foreach ($element->childNodes as $child) {',
            '            if (!$child instanceof DOMElement || $child->localName !== \'Body\') {',
            '                continue;',
            '            }',
            '',
            '            return match ($child->namespaceURI) {',
            '                Meteorology\\Body::NS => Meteorology\\Body::fromElement($child, $path),',
            '                Seismology\\Body::NS => Seismology\\Body::fromElement($child, $path),',
            '                Volcanology\\Body::NS => Volcanology\\Body::fromElement($child, $path),',
            '                default => throw new ParseException(\'Unknown Body namespace "\' . (string) $child->namespaceURI . \'"\', $path),',
            '            };',
            '        }',
            '',
            '        throw new ParseException(\'Missing Body\', $path);',
            '    }',
        ];
    }

    private static function propertyDocblock(Property $property): ?string
    {
        $parts = [];

        if ($property->docType !== null) {
            $parts[] = '@var ' . $property->docType;
        }

        $text = self::describe($property->description);

        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts === [] ? null : '/** ' . implode(' ', $parts) . ' */';
    }

    private static function describe(?Description $description): string
    {
        if ($description === null) {
            return '';
        }

        $text = match (true) {
            $description->meaning !== '' && $description->explanation !== '' => $description->meaning . ': ' . $description->explanation,
            default => $description->meaning . $description->explanation,
        };

        return self::escapeComment(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private static function escapeComment(string $text): string
    {
        return str_replace('*/', '* /', $text);
    }

    private static function nullable(string $type): string
    {
        return str_starts_with($type, '?') ? $type : '?' . $type;
    }

    private static function listDoc(string $type, ?ScalarKind $kind): ?string
    {
        if ($kind !== ScalarKind::StringList) {
            return null;
        }

        return str_starts_with($type, '?') ? 'list<string>|null' : 'list<string>';
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
