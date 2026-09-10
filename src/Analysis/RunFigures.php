<?php

namespace App\Analysis;

use App\Entity\AnalysisRun;

/**
 * The five headline figures of a run, as shown on screen.
 *
 * A run stores its own totals, and those stay the official ones — they are what the
 * panel aggregates and what the report was written from. But the article list can be
 * read with preprints hidden, and then the tiles above it have to describe the set
 * the reader is actually looking at: leaving them at the full total made the page
 * contradict itself, showing "166 artículos" over a list that says "148 de 166".
 *
 * So the figures become a value object with two constructors — the stored ones, and
 * the ones re-derived from a filtered set — and the templates take either. They
 * expose the same getters as AnalysisRun on purpose: _kpi_tiles and _meter are
 * shared with the panel and the researcher page, and neither should need to know
 * which kind of figures it was handed.
 */
final readonly class RunFigures
{
    public function __construct(
        public int $totalArticles,
        public int $totalTypeA,
        public int $totalTypeB,
        public int $totalSelf,
    ) {
    }

    /** The run's own stored totals: every article, exactly as the engine returned them. */
    public static function stored(AnalysisRun $run): self
    {
        return new self(
            $run->getTotalArticles(),
            $run->getTotalTypeA(),
            $run->getTotalTypeB(),
            $run->getTotalSelf(),
        );
    }

    public function getTotalArticles(): int
    {
        return $this->totalArticles;
    }

    public function getTotalTypeA(): int
    {
        return $this->totalTypeA;
    }

    public function getTotalTypeB(): int
    {
        return $this->totalTypeB;
    }

    public function getTotalSelf(): int
    {
        return $this->totalSelf;
    }

    public function getTotalCitations(): int
    {
        return $this->totalTypeA + $this->totalTypeB + $this->totalSelf;
    }

    /** Whether these differ from the run's stored totals — i.e. something was hidden. */
    public function differsFrom(AnalysisRun $run): bool
    {
        return $this->totalArticles !== $run->getTotalArticles()
            || $this->totalTypeA !== $run->getTotalTypeA()
            || $this->totalTypeB !== $run->getTotalTypeB()
            || $this->totalSelf !== $run->getTotalSelf();
    }
}
