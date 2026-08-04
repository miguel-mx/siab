<?php

namespace App\Tests\Engine;

use App\Entity\AnalysisRun;
use App\Enum\RunStatus;
use PHPUnit\Framework\TestCase;

/**
 * The rules cancelling depends on: which states may still be stopped, and when a
 * run has been running long enough that the page should stop implying progress.
 */
final class AnalysisRunCancelTest extends TestCase
{
    public function testOnlyQueuedAndRunningCanBeCanceled(): void
    {
        self::assertTrue(RunStatus::QUEUED->isCancellable());
        self::assertTrue(RunStatus::RUNNING->isCancellable());

        foreach ([RunStatus::COMPLETED, RunStatus::FAILED, RunStatus::CANCELED] as $status) {
            self::assertFalse($status->isCancellable(), $status->value);
        }
    }

    /** Terminal is what makes RunAnalysisHandler drop a message it later picks up. */
    public function testCanceledIsTerminal(): void
    {
        self::assertTrue(RunStatus::CANCELED->isTerminal());
        self::assertFalse(RunStatus::CANCELED->hasFigures());
    }

    public function testCanceledIsNotPresentedAsAFailure(): void
    {
        self::assertNotSame(RunStatus::FAILED->chipClass(), RunStatus::CANCELED->chipClass());
        self::assertSame('Cancelado', RunStatus::CANCELED->label());
    }

    public function testARunIsStalledOnlyAfterItHasBeenRunningTooLong(): void
    {
        $run = (new AnalysisRun())->setStatus(RunStatus::RUNNING);

        $run->setStartedAt(new \DateTimeImmutable('-2 minutes'));
        self::assertFalse($run->isStalled(), 'a normal run takes minutes');

        $run->setStartedAt(new \DateTimeImmutable('-3 hours'));
        self::assertTrue($run->isStalled());
    }

    /**
     * The split between the two ways of getting rid of a run: figures are archived
     * (reversible, and the dashboard's numbers stop counting them), everything else
     * is deleted (permanent, and nothing counted it anyway).
     */
    public function testRunsWithFiguresAreArchivedAndNeverDeleted(): void
    {
        $run = (new AnalysisRun())->setStatus(RunStatus::COMPLETED);

        self::assertTrue($run->isArchivable());
        self::assertFalse($run->isDeletable());
    }

    public function testRunsWithoutFiguresAreDeletedAndNeverArchived(): void
    {
        foreach ([RunStatus::FAILED, RunStatus::CANCELED] as $status) {
            $run = (new AnalysisRun())->setStatus($status);

            self::assertTrue($run->isDeletable(), $status->value);
            self::assertFalse($run->isArchivable(), $status->value);
        }
    }

    public function testARunInFlightIsNeitherDeletableNorArchivable(): void
    {
        foreach ([RunStatus::QUEUED, RunStatus::RUNNING] as $status) {
            $run = (new AnalysisRun())->setStatus($status);

            self::assertFalse($run->isDeletable(), $status->value.' — cancel it first');
            self::assertFalse($run->isArchivable(), $status->value);
        }
    }

    public function testAnArchivedRunIsNotArchivedTwice(): void
    {
        $run = (new AnalysisRun())
            ->setStatus(RunStatus::COMPLETED)
            ->setDiscardedAt(new \DateTimeImmutable());

        self::assertTrue($run->isArchived());
        self::assertFalse($run->isArchivable());
    }

    public function testQueuedAndFinishedRunsAreNeverCalledStalled(): void
    {
        $queued = (new AnalysisRun())->setStatus(RunStatus::QUEUED);
        self::assertFalse($queued->isStalled(), 'nothing has started, so nothing is stuck');

        $done = (new AnalysisRun())
            ->setStatus(RunStatus::COMPLETED)
            ->setStartedAt(new \DateTimeImmutable('-3 hours'));
        self::assertFalse($done->isStalled());
    }
}
