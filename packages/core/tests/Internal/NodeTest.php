<?php

declare(strict_types=1);

namespace JmaXml\Tests\Internal;

use DOMDocument;
use DOMElement;
use JmaXml\Exception\ParseException;
use JmaXml\Internal\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class NodeTest extends TestCase
{
    #[Test]
    public function findsDirectChildByNamespaceAndLocalName(): void
    {
        $root = $this->element('<r xmlns="urn:a" xmlns:b="urn:b"><x>1</x><b:x>2</b:x><y><x>3</x></y></r>');

        $this->assertSame('1', Node::text(Node::one($root, 'urn:a', 'x', '/r')));
        $this->assertSame('2', Node::text(Node::one($root, 'urn:b', 'x', '/r')));
        $this->assertCount(1, Node::many($root, 'urn:a', 'x'));
        $this->assertNull(Node::optional($root, 'urn:a', 'z'));
    }

    #[Test]
    public function missingRequiredChildReportsPath(): void
    {
        $root = $this->element('<r xmlns="urn:a"><x>1</x></r>');

        $exception = $this->catchParseException(fn() => Node::one($root, 'urn:a', 'z', '/Report/Head'));

        $this->assertSame('/Report/Head/z', $exception->path);
    }

    #[Test]
    public function atLeastOneRejectsEmpty(): void
    {
        $root = $this->element('<r xmlns="urn:a"><x>1</x></r>');

        $this->assertCount(1, Node::atLeastOne($root, 'urn:a', 'x', '/r'));
        $this->assertSame('/r/z', $this->catchParseException(fn() => Node::atLeastOne($root, 'urn:a', 'z', '/r'))->path);
    }

    #[Test]
    public function mapManyPassesOneBasedIndexedPaths(): void
    {
        $root = $this->element('<r xmlns="urn:a"><x>1</x><x>2</x></r>');

        $paths = Node::mapMany(Node::many($root, 'urn:a', 'x'), '/r/x', static fn(DOMElement $e, string $p): string => $p);

        $this->assertSame(['/r/x[1]', '/r/x[2]'], $paths);
    }

    #[Test]
    public function mapOptionalSkipsMissingElement(): void
    {
        $root = $this->element('<r xmlns="urn:a"><x>1</x></r>');
        $map = static fn(DOMElement $e, string $p): string => $p . '=' . Node::text($e);

        $this->assertSame('/r/x=1', Node::mapOptional(Node::optional($root, 'urn:a', 'x'), '/r/x', $map));
        $this->assertNull(Node::mapOptional(Node::optional($root, 'urn:a', 'z'), '/r/z', $map));
    }

    #[Test]
    public function requiredAttributeReportsPath(): void
    {
        $root = $this->element('<r xmlns="urn:a" type="t"/>');

        $this->assertSame('t', Node::attribute($root, 'type'));
        $this->assertNull(Node::attribute($root, 'codeType'));
        $this->assertSame('t', Node::requiredAttribute($root, 'type', '/r'));
        $this->assertSame('/r/@codeType', $this->catchParseException(fn() => Node::requiredAttribute($root, 'codeType', '/r'))->path);
    }

    #[Test]
    public function convertsTokens(): void
    {
        $this->assertSame('a b', Node::toToken("  a \n b  "));
        $this->assertSame('4 ', Node::text($this->element('<MaxInt>4 </MaxInt>')));
    }

    #[Test]
    public function trimsSingleLineTextButKeepsMultiLineTextVerbatim(): void
    {
        $this->assertSame('4', Node::toText('4 '));
        $this->assertSame('9074', Node::toText("9074\n"));
        $this->assertSame('　調査結果がまとまり次第お知らせします。', Node::toText("\n　調査結果がまとまり次第お知らせします。\n"));
        $this->assertSame('', Node::toText("\n          "));
        $this->assertSame("  見出し\n本文\n", Node::toText("  見出し\n本文\n"));
    }

    #[Test]
    public function convertsDateTimeKeepingTimezone(): void
    {
        $this->assertSame('2009-10-01T04:50:01+00:00', Node::toDateTime('2009-10-01T04:50:01Z', 'p')->format(DATE_ATOM));
        $this->assertSame('+09:00', Node::toDateTime('2009-10-01T13:50:00+09:00', 'p')->getTimezone()->getName());
        $this->assertSame('2009-10-01T13:50:00+09:00', Node::toDateTime(' 2009-10-01T13:50:00+09:00 ', 'p')->format(DATE_ATOM));
        $this->assertSame('p', $this->catchParseException(fn() => Node::toDateTime('2009-13-01T00:00:00+09:00', 'p'))->path);
        $this->assertSame('p', $this->catchParseException(fn() => Node::toDateTime('', 'p'))->path);
    }

    #[Test]
    public function nillableDateTimeReturnsNullForXsiNil(): void
    {
        $nil = $this->element('<TargetDateTime xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/>');
        $value = $this->element('<TargetDateTime>2010-07-05T06:55:00+09:00</TargetDateTime>');

        $this->assertNull(Node::toNillableDateTime($nil, 'p'));
        $this->assertSame('2010-07-05T06:55:00+09:00', Node::toNillableDateTime($value, 'p')?->format(DATE_ATOM));
    }

    #[Test]
    public function convertsDurations(): void
    {
        $long = Node::toDuration('P1DT19H', 'p');
        $this->assertSame(1, $long->d);
        $this->assertSame(19, $long->h);
        $this->assertSame(3, Node::toDuration('PT3H', 'p')->h);
        $this->assertSame(0.5, Node::toDuration('PT1.5S', 'p')->f);
        $this->assertSame(1, Node::toDuration('-P1D', 'p')->invert);
        $this->assertSame('p', $this->catchParseException(fn() => Node::toDuration('3 hours', 'p'))->path);
    }

    #[Test]
    #[DataProvider('floats')]
    public function convertsFloats(string $raw, ?float $expected): void
    {
        $this->assertSame($expected, Node::toFloat($raw, 'p'));
    }

    /**
     * @return iterable<array{string, ?float}>
     */
    public static function floats(): iterable
    {
        yield ['5.9', 5.9];
        yield [' 5.9 ', 5.9];
        yield ['+5.9', 5.9];
        yield ['-0', -0.0];
        yield ['NaN', null];
        yield ['INF', INF];
        yield ['-INF', -INF];
    }

    #[Test]
    public function floatRejectsEmptyAndGarbage(): void
    {
        $this->assertSame('p', $this->catchParseException(fn() => Node::toFloat('', 'p'))->path);
        $this->assertSame('p', $this->catchParseException(fn() => Node::toFloat('abc', 'p'))->path);
    }

    #[Test]
    public function nullableNumbersTreatEmptyAsNull(): void
    {
        $this->assertNull(Node::toNullableFloat('', 'p'));
        $this->assertNull(Node::toNullableFloat('NaN', 'p'));
        $this->assertSame(1.5, Node::toNullableFloat('1.5', 'p'));
        $this->assertNull(Node::toNullableInt('', 'p'));
        $this->assertSame(12, Node::toNullableInt(' 12 ', 'p'));
    }

    #[Test]
    public function convertsIntsAndBools(): void
    {
        $this->assertSame(3, Node::toInt('3', 'p'));
        $this->assertSame(3, Node::toInt(' 3 ', 'p'));
        $this->assertSame('p', $this->catchParseException(fn() => Node::toInt('3.5', 'p'))->path);
        $this->assertTrue(Node::toBool('true', 'p'));
        $this->assertTrue(Node::toBool('1', 'p'));
        $this->assertFalse(Node::toBool('false', 'p'));
        $this->assertFalse(Node::toBool('0', 'p'));
        $this->assertSame('p', $this->catchParseException(fn() => Node::toBool('yes', 'p'))->path);
    }

    #[Test]
    public function splitsLists(): void
    {
        $this->assertSame(['気象庁', '大阪管区気象台'], Node::toList(" 気象庁  大阪管区気象台\n"));
        $this->assertSame([], Node::toList(''));
    }

    private function element(string $xml): DOMElement
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $root = $document->documentElement;
        $this->assertInstanceOf(DOMElement::class, $root);

        return $root;
    }

    /**
     * @param callable(): mixed $callback
     */
    private function catchParseException(callable $callback): ParseException
    {
        try {
            $callback();
        } catch (ParseException $exception) {
            return $exception;
        }

        $this->fail('ParseException was not thrown');
    }
}
