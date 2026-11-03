<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Php;

use JmaXml\Codegen\Schema\QName;
use LogicException;

/**
 * How a simple-typed value is represented in PHP and converted from its lexical form.
 *
 * @author shimomo
 */
enum ScalarKind
{
    case String;
    case Token;
    case DateTime;
    case Duration;
    case Float;
    case NullableFloat;
    case Int;
    case NullableInt;
    case Bool;
    case StringList;

    private const string ELEMENT_BASIS = 'http://xml.kishou.go.jp/jmaxml1/elementBasis1/';

    public static function fromBase(QName $base): self
    {
        if ($base->namespace === self::ELEMENT_BASIS) {
            return match ($base->localName) {
                'nullablefloat' => self::NullableFloat,
                'nullableinteger' => self::NullableInt,
                default => throw new LogicException('Unsupported simple type ' . $base->key()),
            };
        }

        if ($base->namespace !== QName::XS) {
            throw new LogicException('Unsupported simple type ' . $base->key());
        }

        return match ($base->localName) {
            'string', 'anyURI', 'normalizedString', 'gMonthDay' => self::String,
            'token' => self::Token,
            'dateTime' => self::DateTime,
            'duration' => self::Duration,
            'float', 'double', 'decimal' => self::Float,
            'int', 'integer', 'unsignedByte', 'byte', 'short', 'unsignedShort', 'long', 'unsignedInt', 'nonNegativeInteger', 'positiveInteger' => self::Int,
            'boolean' => self::Bool,
            'list' => self::StringList,
            default => throw new LogicException('Unsupported simple type ' . $base->key()),
        };
    }

    /**
     * The declared PHP type of a single value.
     */
    public function phpType(): string
    {
        return match ($this) {
            self::String, self::Token => 'string',
            self::DateTime => 'DateTimeImmutable',
            self::Duration => 'DateInterval',
            self::Float, self::NullableFloat => '?float',
            self::Int => 'int',
            self::NullableInt => '?int',
            self::Bool => 'bool',
            self::StringList => 'array',
        };
    }

    /**
     * The docblock type of a single value.
     */
    public function docType(): string
    {
        return match ($this) {
            self::StringList => 'list<string>',
            self::Float, self::NullableFloat => 'float|null',
            self::NullableInt => 'int|null',
            default => $this->phpType(),
        };
    }

    public function isNullable(): bool
    {
        return in_array($this, [self::Float, self::NullableFloat, self::NullableInt], true);
    }

    /**
     * A PHP expression converting the raw string expression $raw; $path is the PHP expression of the node path.
     */
    public function conversion(string $raw, string $path): string
    {
        return match ($this) {
            self::String => $raw,
            self::Token => 'Node::toToken(' . $raw . ')',
            self::DateTime => 'Node::toDateTime(' . $raw . ', ' . $path . ')',
            self::Duration => 'Node::toDuration(' . $raw . ', ' . $path . ')',
            self::Float => 'Node::toFloat(' . $raw . ', ' . $path . ')',
            self::NullableFloat => 'Node::toNullableFloat(' . $raw . ', ' . $path . ')',
            self::Int => 'Node::toInt(' . $raw . ', ' . $path . ')',
            self::NullableInt => 'Node::toNullableInt(' . $raw . ', ' . $path . ')',
            self::Bool => 'Node::toBool(' . $raw . ', ' . $path . ')',
            self::StringList => 'Node::toList(' . $raw . ')',
        };
    }

    /**
     * Short class names the conversion or the declared type needs imported.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        return match ($this) {
            self::DateTime => ['DateTimeImmutable'],
            self::Duration => ['DateInterval'],
            default => [],
        };
    }
}
