<?php

declare(strict_types=1);

namespace JmaXml\Tests;

use JmaXml\Envelope;
use JmaXml\Exception\ParseException;
use JmaXml\Report;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function readsControlAndHeadOnly(): void
    {
        $envelope = Envelope::fromFile(Fixtures::sample('32-35_01_03_240613_VXSE53.xml'));

        $this->assertSame('訓練', $envelope->control->status);
        $this->assertSame('20091001134500', $envelope->head->eventID);
    }

    #[Test]
    public function doesNotRequireAValidBody(): void
    {
        $xml = (string) preg_replace(
            '#<ArrivalTime>[^<]*</ArrivalTime>#',
            '',
            (string) file_get_contents(Fixtures::sample('32-35_01_03_240613_VXSE53.xml')),
            1,
        );

        $this->assertSame('20091001134500', Envelope::fromString($xml)->head->eventID);

        $this->expectException(ParseException::class);

        Report::fromString($xml);
    }
}
