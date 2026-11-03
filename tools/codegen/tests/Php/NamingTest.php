<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests\Php;

use JmaXml\Codegen\Php\Naming;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class NamingTest extends TestCase
{
    #[Test]
    public function namesClassesAndProperties(): void
    {
        $this->assertSame('Report', Naming::className('type.report'));
        $this->assertSame('VolcanoInfo', Naming::className('type.volcanoInfo'));
        $this->assertSame('eventID', Naming::propertyName('EventID'));
        $this->assertSame('targetDTDubious', Naming::propertyName('TargetDTDubious'));
        $this->assertSame('codeType', Naming::propertyName('codeType'));
    }

    #[Test]
    public function rejectsReservedClassName(): void
    {
        $this->expectException(LogicException::class);

        Naming::className('type.list');
    }
}
