<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Php;

use LogicException;

/**
 * PHP names for schema types, elements and attributes.
 *
 * @author shimomo
 */
final class Naming
{
    /**
     * Names PHP reserves for classes (case-insensitive).
     */
    private const array RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include',
        'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace',
        'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly',
        'require', 'resource', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try',
        'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    public static function className(string $typeName): string
    {
        $name = ucfirst(str_starts_with($typeName, 'type.') ? substr($typeName, 5) : $typeName);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || in_array(strtolower($name), self::RESERVED, true)) {
            throw new LogicException('Cannot use "' . $name . '" (from ' . $typeName . ') as a PHP class name');
        }

        return $name;
    }

    public static function propertyName(string $name): string
    {
        $property = lcfirst($name);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $property) !== 1) {
            throw new LogicException('Cannot use "' . $property . '" as a PHP property name');
        }

        return $property;
    }
}
