<?php

namespace App\Tests\Analysis;

use App\Analysis\RunFigures;
use App\Entity\AnalysisRun;
use PHPUnit\Framework\TestCase;

/**
 * The figures the run page renders. Their whole job is to let the tiles describe
 * whichever set of articles is on screen without the shared partials having to know
 * which of the two they were handed.
 */
final class RunFiguresTest extends TestCase
{
    private function completedRun(): AnalysisRun
    {
        return (new AnalysisRun())
            ->setTotalArticles(166)
            ->setTotalTypeA(912)
            ->setTotalTypeB(344)
            ->setTotalSelf(211);
    }

    public function testStoredFiguresMirrorTheRun(): void
    {
        $figures = RunFigures::stored($this->completedRun());

        self::assertSame(166, $figures->getTotalArticles());
        self::assertSame(1467, $figures->getTotalCitations());
        self::assertFalse($figures->differsFrom($this->completedRun()));
    }

    /**
     * The case the banner on the run page keys off: figures re-derived over a
     * narrowed set have to be recognisable as not being the run's own.
     */
    public function testRecomputedFiguresAreRecognisableAsDifferent(): void
    {
        $withoutPreprints = new RunFigures(148, 912, 344, 135);

        self::assertTrue($withoutPreprints->differsFrom($this->completedRun()));
        self::assertSame(1391, $withoutPreprints->getTotalCitations());
    }

    /** A run whose every article was hidden still answers, rather than dividing by zero. */
    public function testAnEmptySetIsStillAnAnswer(): void
    {
        $empty = new RunFigures(0, 0, 0, 0);

        self::assertSame(0, $empty->getTotalCitations());
        self::assertTrue($empty->differsFrom($this->completedRun()));
    }
}
