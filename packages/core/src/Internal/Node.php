<?php

declare(strict_types=1);

namespace JmaXml\Internal;

use DateInterval;
use DateTimeImmutable;
use DOMElement;
use Exception;
use JmaXml\Exception\ParseException;

/**
 * Child lookup and value conversion helpers used by the generated model classes.
 *
 * @internal
 *
 * @author shimomo
 */
final class Node
{
    public const string XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    private const string XML_WHITESPACE = " \t\n\r";

    private const string DATE_TIME_PATTERN = '/^(-?\d{4,}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/';

    private const string DURATION_PATTERN = '/^(-)?P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.(\d+))?S)?)?$/';

    private const string FLOAT_PATTERN = '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/';

    private const string INT_PATTERN = '/^[+-]?\d+$/';

    public static function one(DOMElement $parent, string $namespace, string $localName, string $path): DOMElement
    {
        return self::optional($parent, $namespace, $localName)
            ?? throw new ParseException('Missing required element', $path . '/' . $localName);
    }

    public static function optional(DOMElement $parent, string $namespace, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName && $child->namespaceURI === $namespace) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    public static function many(DOMElement $parent, string $namespace, string $localName): array
    {
        $elements = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName && $child->namespaceURI === $namespace) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * @return non-empty-list<DOMElement>
     */
    public static function atLeastOne(DOMElement $parent, string $namespace, string $localName, string $path): array
    {
        $elements = self::many($parent, $namespace, $localName);

        if ($elements === []) {
            throw new ParseException('Missing required element', $path . '/' . $localName);
        }

        return $elements;
    }

    /**
     * @template T
     *
     * @param callable(DOMElement, string): T $map
     *
     * @return T|null
     */
    public static function mapOptional(?DOMElement $element, string $path, callable $map): mixed
    {
        return $element === null ? null : $map($element, $path);
    }

    /**
     * @template T
     *
     * @param list<DOMElement> $elements
     * @param callable(DOMElement, string): T $map
     *
     * @return list<T>
     */
    public static function mapMany(array $elements, string $path, callable $map): array
    {
        $values = [];

        foreach ($elements as $index => $element) {
            $values[] = $map($element, $path . '[' . ($index + 1) . ']');
        }

        return $values;
    }

    public static function attribute(DOMElement $element, string $name): ?string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }

    public static function requiredAttribute(DOMElement $element, string $name, string $path): string
    {
        return self::attribute($element, $name)
            ?? throw new ParseException('Missing required attribute', $path . '/@' . $name);
    }

    public static function text(DOMElement $element): string
    {
        return $element->textContent;
    }

    /**
     * Converts an xs:string value: single-line values lose surrounding XML whitespace, while text that spans
     * several lines (plain text, spec 1.3.8) is returned verbatim so that its layout survives.
     *
     * Telegrams carry values such as <MaxInt>4 </MaxInt> and <Code>9074\n</Code>.
     */
    public static function toText(string $raw): string
    {
        $trimmed = trim($raw, self::XML_WHITESPACE);

        return str_contains($trimmed, "\n") || str_contains($trimmed, "\r") ? $raw : $trimmed;
    }

    public static function toToken(string $raw): string
    {
        return (string) preg_replace('/[ \t\n\r]+/', ' ', trim($raw, self::XML_WHITESPACE));
    }

    public static function toDateTime(string $raw, string $path): DateTimeImmutable
    {
        $value = trim($raw, self::XML_WHITESPACE);

        if (preg_match(self::DATE_TIME_PATTERN, $value, $matches) !== 1) {
            throw new ParseException('Invalid xs:dateTime "' . $raw . '"', $path);
        }

        [, $dateTime, $fraction, $timezone] = $matches;
        $format = 'Y-m-d\TH:i:s';
        $normalized = $dateTime;

        if ($fraction !== '') {
            $format .= '.u';
            $normalized .= '.' . substr($fraction . '000000', 0, 6);
        }

        $parsed = DateTimeImmutable::createFromFormat($format . 'T', $normalized . $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ParseException('Invalid xs:dateTime "' . $raw . '"', $path);
        }

        return $parsed;
    }

    public static function toNillableDateTime(DOMElement $element, string $path): ?DateTimeImmutable
    {
        $nil = trim($element->getAttributeNS(self::XSI_NAMESPACE, 'nil'), self::XML_WHITESPACE);

        if ($nil === 'true' || $nil === '1') {
            return null;
        }

        return self::toDateTime(self::text($element), $path);
    }

    public static function toDuration(string $raw, string $path): DateInterval
    {
        $value = trim($raw, self::XML_WHITESPACE);

        if (
            preg_match(self::DURATION_PATTERN, $value, $matches, PREG_UNMATCHED_AS_NULL) !== 1
            || $value === 'P'
            || $value === '-P'
            || str_ends_with($value, 'T')
        ) {
            throw new ParseException('Invalid xs:duration "' . $raw . '"', $path);
        }

        $spec = (string) preg_replace(['/^-/', '/\.\d+S$/'], ['', 'S'], $value);

        try {
            $interval = new DateInterval($spec);
        } catch (Exception $exception) {
            throw new ParseException('Invalid xs:duration "' . $raw . '"', $path, $exception);
        }

        if ($matches[8] !== null) {
            // DateInterval's constructor rejects fractional seconds, but the property is writable at runtime.
            /** @psalm-suppress InaccessibleProperty */
            $interval->f = (float) ('0.' . $matches[8]);
        }

        if ($matches[1] !== null) {
            $interval->invert = 1;
        }

        return $interval;
    }

    public static function toFloat(string $raw, string $path): ?float
    {
        $value = trim($raw, self::XML_WHITESPACE);

        return match (true) {
            $value === 'NaN' => null,
            $value === 'INF', $value === '+INF' => INF,
            $value === '-INF' => -INF,
            preg_match(self::FLOAT_PATTERN, $value) === 1 => (float) $value,
            default => throw new ParseException('Invalid xs:float "' . $raw . '"', $path),
        };
    }

    public static function toNullableFloat(string $raw, string $path): ?float
    {
        return trim($raw, self::XML_WHITESPACE) === '' ? null : self::toFloat($raw, $path);
    }

    public static function toInt(string $raw, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = trim($raw, self::XML_WHITESPACE);
        // filter_var() rejects leading zeros and a plus sign, which xs:int allows ("+007").
        $digits = ltrim((string) preg_replace('/^([+-]?)0+(?=\d)/', '$1', $value), '+');
        $int = preg_match(self::INT_PATTERN, $value) === 1
            ? filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])
            : false;

        if ($int === false) {
            throw new ParseException('Invalid integer "' . $raw . '" (expected ' . $min . ' to ' . $max . ')', $path);
        }

        return $int;
    }

    public static function toNullableInt(string $raw, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        return trim($raw, self::XML_WHITESPACE) === '' ? null : self::toInt($raw, $path, $min, $max);
    }

    public static function toBool(string $raw, string $path): bool
    {
        return match (trim($raw, self::XML_WHITESPACE)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new ParseException('Invalid xs:boolean "' . $raw . '"', $path),
        };
    }

    /**
     * @return list<string>
     */
    public static function toList(string $raw): array
    {
        $items = preg_split('/[ \t\n\r]+/', trim($raw, self::XML_WHITESPACE), -1, PREG_SPLIT_NO_EMPTY);

        return $items === false ? [] : $items;
    }
}
