<?php

declare(strict_types=1);

namespace JmaXml\Tests;

use JmaXml\Body;
use JmaXml\Meteorology;
use JmaXml\Report;
use JmaXml\Seismology;
use JmaXml\Tests\Support\Fixtures;
use JmaXml\Volcanology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every official sample telegram must parse without exceptions.
 *
 * @author shimomo
 */
final class SampleCorpusTest extends TestCase
{
    #[Test]
    #[DataProvider('samples')]
    public function parsesOfficialSample(string $path): void
    {
        $this->assertInstanceOf(Body::class, Report::fromFile($path)->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function samples(): iterable
    {
        foreach (Fixtures::samplePaths() as $path) {
            yield basename($path) => [$path];
        }
    }

    #[Test]
    public function bodyNamespacesAreDistributedAsExpected(): void
    {
        $classes = array_map(static fn(string $path): string => Report::fromFile($path)->body::class, Fixtures::samplePaths());

        $this->assertSame(
            [Meteorology\Body::class => 343, Seismology\Body::class => 184, Volcanology\Body::class => 19],
            array_count_values($classes),
        );
    }
}
