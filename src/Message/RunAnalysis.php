<?php

namespace App\Message;

/**
 * Ask a worker to execute an already-persisted AnalysisRun (status QUEUED).
 * Only the id travels — the payload is heavy and lives in the database.
 */
final readonly class RunAnalysis
{
    public function __construct(
        public int $analysisRunId,
        public bool $wantReport = false,
    ) {
    }
}
