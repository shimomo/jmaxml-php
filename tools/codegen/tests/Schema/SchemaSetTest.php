<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests\Schema;

use JmaXml\Codegen\Schema\ChildElement;
use JmaXml\Codegen\Schema\QName;
use JmaXml\Codegen\Schema\SchemaSet;
use JmaXml\Tests\Support\Fixtures;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class SchemaSetTest extends TestCase
{
    private const string XS = 'http://www.w3.org/2001/XMLSchema';

    private const string JMX = 'http://xml.kishou.go.jp/jmaxml1/';

    private const string IB = 'http://xml.kishou.go.jp/jmaxml1/informationBasis1/';

    private const string EB = 'http://xml.kishou.go.jp/jmaxml1/elementBasis1/';

    private const string ADD = 'http://xml.kishou.go.jp/jmaxml1/addition1/';

    #[Test]
    public function loadsAllComplexTypes(): void
    {
        $schemas = $this->schemas();

        $this->assertCount(274, $schemas->complexTypes());
        $this->assertSame('jmx_seis', $schemas->prefixFor('http://xml.kishou.go.jp/jmaxml1/body/seismology1/'));
    }

    #[Test]
    public function resolvesHeadElements(): void
    {
        $head = $this->schemas()->complexType(new QName(self::IB, 'type.head'));

        $this->assertSame('jmx_ib', $head->prefix);
        $this->assertSame(
            ['Title', 'ReportDateTime', 'TargetDateTime', 'TargetDTDubious', 'TargetDuration', 'ValidDateTime', 'EventID', 'InfoType', 'Serial', 'InfoKind', 'InfoKindVersion', 'Headline'],
            array_map(static fn(ChildElement $element): string => $element->name, $head->elements),
        );

        $byName = array_column(array_map(static fn(ChildElement $e): array => [$e->name, $e], $head->elements), 1, 0);
        $this->assertTrue($byName['TargetDateTime']->nillable);
        $this->assertSame(1, $byName['TargetDateTime']->minOccurs);
        $this->assertSame(0, $byName['TargetDuration']->minOccurs);
        $this->assertEquals(new QName(self::XS, 'duration'), $byName['TargetDuration']->type);
        $this->assertSame(self::IB, $byName['Title']->namespace);
    }

    #[Test]
    public function resolvesElementReferencesToTheirNamespace(): void
    {
        $area = $this->schemas()->complexType(new QName(self::IB, 'type.area'));
        $coordinate = array_values(array_filter($area->elements, static fn(ChildElement $e): bool => $e->name === 'Coordinate'))[0];

        $this->assertSame(self::EB, $coordinate->namespace);
        $this->assertEquals(new QName(self::EB, 'type.Coordinate'), $coordinate->type);
        $this->assertNull($coordinate->maxOccurs);
    }

    #[Test]
    public function readsSimpleContentAndAttributes(): void
    {
        $magnitude = $this->schemas()->complexType(new QName(self::EB, 'type.Magnitude'));

        $this->assertEquals(new QName(self::XS, 'float'), $magnitude->simpleContentBase);
        $this->assertSame(['type', 'condition', 'description'], array_map(static fn($a): string => $a->name, $magnitude->attributes));
        $this->assertTrue($magnitude->attributes[0]->required);
        $this->assertFalse($magnitude->attributes[1]->required);
    }

    #[Test]
    public function recordsAnyWildcards(): void
    {
        $schemas = $this->schemas();

        $this->assertSame('##other', $schemas->complexType(new QName(self::JMX, 'type.report'))->anyNamespace);
        $this->assertSame(self::ADD, $schemas->complexType(new QName(self::IB, 'type.item'))->anyNamespace);
        $this->assertNull($schemas->complexType(new QName(self::IB, 'type.head'))->anyNamespace);
    }

    #[Test]
    public function resolvesSimpleTypes(): void
    {
        $schemas = $this->schemas();

        $this->assertEquals(new QName(self::XS, 'string'), $schemas->simpleTypeBase(new QName(self::JMX, 'enum.UNION.type.control.Status')));
        $this->assertEquals(new QName(self::XS, 'list'), $schemas->simpleTypeBase(new QName(self::JMX, 'list.type.control.PublishingOffice')));
        $this->assertEquals(new QName(self::EB, 'nullablefloat'), $schemas->simpleTypeBase(new QName(self::EB, 'nullablefloat')));
        $this->assertEquals(new QName(self::XS, 'int'), $schemas->simpleTypeBase(new QName(self::XS, 'int')));
        $this->assertTrue($schemas->isComplexType(new QName(self::EB, 'type.Coordinate')));
        $this->assertFalse($schemas->isComplexType(new QName(self::XS, 'string')));
    }

    #[Test]
    public function rejectsUnsupportedConstructs(): void
    {
        $this->expectException(LogicException::class);

        SchemaSet::load(dirname(__DIR__) . '/Fixtures/unsupported/choice.xsd');
    }

    private function schemas(): SchemaSet
    {
        return SchemaSet::load(Fixtures::xsdDir() . '/jmx.xsd');
    }
}
