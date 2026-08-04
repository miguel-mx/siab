<?php

namespace App\Enum;

/**
 * Lifecycle of an AnalysisRun. Mirrors the states surfaced in the dashboard:
 * queued/running are transient; the rest are terminal.
 * CANCELED = stopped by a person, which is not the same as FAILED: nothing went
 * wrong with the analysis, so it never reads as an engine problem.
 *
 * There is deliberately no "needs review" state. An analysis that produced figures
 * is COMPLETED even when the engine returned warnings: the warnings qualify how its
 * numbers are read, they do not make the run unfinished. They are shown on the run
 * itself, where the numbers they qualify are.
 */
enum RunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELED = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELED], true);
    }

    /** Whether a person may still stop this run. */
    public function isCancellable(): bool
    {
        return $this === self::QUEUED || $this === self::RUNNING;
    }

    /** Spanish label for the UI. */
    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'En cola',
            self::RUNNING => 'En proceso',
            self::COMPLETED => 'Completo',
            self::FAILED => 'Fallido',
            self::CANCELED => 'Cancelado',
        };
    }

    /** Status-chip modifier in assets/styles/app.css. */
    public function chipClass(): string
    {
        return match ($this) {
            self::QUEUED, self::RUNNING => 'run',
            self::COMPLETED => 'done',
            self::FAILED => 'fail',
            self::CANCELED => 'off',
        };
    }

    /** Whether this run has figures worth plotting in the A/B/self meter. */
    public function hasFigures(): bool
    {
        return $this === self::COMPLETED;
    }
}
