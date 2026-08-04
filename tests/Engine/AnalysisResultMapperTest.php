<?php

namespace App\Tests\Engine;

use App\Engine\AnalysisResultMapper;
use App\Engine\Dto\AnalysisResultDto;
use App\Entity\AnalysisRun;
use PHPUnit\Framework\TestCase;

/**
 * Offline verification of the DTO parsing + entity mapping using the real
 * /analyze response for Daniel Juan-Pineda (tests/Fixtures/analyze_pineda.json).
 * Proves the independently re-derived per-citing-work A/B/self labels reconcile
 * with the engine's per-article counts.
 */
final class AnalysisResultMapperTest extends TestCase
{
    private function loadDto(): AnalysisResultDto
    {
        $json = file_get_contents(__DIR__ . '/../Fixtures/analyze_pineda.json');
        self::assertNotFalse($json, 'fixture missing');

        return AnalysisResultDto::fromResponse(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTotalsMatchTheKnownRun(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, $this->loadDto());

        self::assertSame(41, $run->getTotalArticles());
        self::assertSame(113, $run->getTotalTypeA());
        self::assertSame(44, $run->getTotalTypeB());
        self::assertSame(68, $run->getTotalSelf());
        self::assertSame([], $run->getFlags());
        self::assertNotNull($run->getRawSnapshot());
        self::assertNull($run->getReport()); // want_report was false
    }

    public function testPerWorkLabelsReconcileWithEngineCounts(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, $this->loadDto());

        $labelA = $labelB = $labelSelf = 0;
        foreach ($run->getArticles() as $article) {
            $a = $b = $s = 0;
            foreach ($article->getCitingWorks() as $cw) {
                match ($cw->getClassification()) {
                    'A' => $a++,
                    'B' => $b++,
                    'self' => $s++,
                };
            }
            // Every article's re-derived labels must equal the engine's counts.
            self::assertSame($article->getCitesTypeA(), $a, 'Type A mismatch on: ' . $article->getTitle());
            self::assertSame($article->getCitesTypeB(), $b, 'Type B mismatch on: ' . $article->getTitle());
            self::assertSame($article->getCitesSelf(), $s, 'self mismatch on: ' . $article->getTitle());
            $labelA += $a; $labelB += $b; $labelSelf += $s;
        }

        self::assertSame(113, $labelA);
        self::assertSame(44, $labelB);
        self::assertSame(68, $labelSelf);
    }

    public function testOpenAlexIdsAreNormalizedToBareForm(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, $this->loadDto());

        $first = $run->getArticles()->first();
        self::assertMatchesRegularExpression('/^W\d+$/', $first->getOpenalexId());
    }
}
