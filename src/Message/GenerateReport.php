<?php

namespace App\Message;

/**
 * Ask a worker to write the narrative report for a run that already has figures.
 * Separate from RunAnalysis: it can be requested long after the analysis, and it
 * only touches the model — no OpenAlex calls.
 */
final readonly class GenerateReport
{
    public function __construct(
        public int $analysisRunId,
    ) {
    }
}
