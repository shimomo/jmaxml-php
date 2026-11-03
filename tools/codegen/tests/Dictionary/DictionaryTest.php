<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests\Dictionary;

use JmaXml\Codegen\Dictionary\Description;
use JmaXml\Codegen\Dictionary\Dictionary;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class DictionaryTest extends TestCase
{
    #[Test]
    public function describesTypesElementsAndAttributes(): void
    {
        $dictionary = Dictionary::fromXlsx(Fixtures::dictionaryPath());

        $this->assertEquals(
            new Description('標題', '情報を示す標題。具体的な内容が判別できる名称であり、可視化を目的として利用する。'),
            $dictionary->forElement('jmx_ib', 'type.head', 'Title'),
        );
        $this->assertSame('Body型の要素を示す', $dictionary->forType('jmx_seis', 'type.Body')?->explanation);
        $this->assertEquals(
            new Description('状態', '値の状態（「不明」など）を示す。'),
            $dictionary->forAttribute('jmx_eb', 'type.Magnitude', 'condition'),
        );
        $this->assertNull($dictionary->forElement('jmx_ib', 'type.head', 'NoSuchElement'));
    }

    #[Test]
    public function emptyDictionaryDescribesNothing(): void
    {
        $this->assertNull(Dictionary::empty()->forType('jmx_seis', 'type.Body'));
    }
}
