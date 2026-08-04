<?php

namespace App\Tests\Engine;

use App\Entity\AnalysisRun;
use App\Enum\AnalysisSource;
use PHPUnit\Framework\TestCase;

/**
 * Which sources a run may use, and how that survives on the run itself.
 *
 * The bug this closes: `sources` kept its 'openalex' default forever because
 * nothing ever wrote it, so every run claimed OpenAlex only while actually
 * merging Scopus and zbMATH results.
 */
final class AnalysisSourceTest extends TestCase
{
    public function testOpenalexIsNeverOffered(): void
    {
        // It is the only source with author identifiers: without it every citation
        // falls back to Type A and the analysis means nothing.
        self::assertFalse(AnalysisSource::OPENALEX->isOptional());
        self::assertNotContains(AnalysisSource::OPENALEX, AnalysisSource::optional());
    }

    public function testOnlyScopusAndWosDependOnAKey(): void
    {
        self::assertNotNull(AnalysisSource::SCOPUS->apiKeySetting());
        self::assertNotNull(AnalysisSource::WOS->apiKeySetting());

        self::assertNull(AnalysisSource::ZBMATH->apiKeySetting(), 'zbMATH is open');
        self::assertNull(AnalysisSource::INSPIRE->apiKeySetting(), 'INSPIRE is open');
    }

    public function testAStoredSelectionReadsBackAsSources(): void
    {
        self::assertSame(
            [AnalysisSource::OPENALEX, AnalysisSource::ZBMATH],
            AnalysisSource::parseList('openalex,zbmath'),
        );
    }

    public function testUnknownStoredValuesAreIgnoredRatherThanFatal(): void
    {
        // A source could be dropped from the code while old runs still name it.
        self::assertSame(
            [AnalysisSource::OPENALEX],
            AnalysisSource::parseList('openalex,scielo, '),
        );
    }

    public function testARunNamesEverySourceItUsed(): void
    {
        $run = (new AnalysisRun())->setSources('openalex,scopus,zbmath');

        self::assertSame(['OpenAlex', 'Scopus', 'zbMATH'], $run->getSourceLabels());
    }

    public function testEverySourceHasALabelAndAnExplanation(): void
    {
        foreach (AnalysisSource::cases() as $source) {
            self::assertNotSame('', $source->label(), $source->value);
            self::assertNotSame('', $source->hint(), $source->value);
        }
    }
}
