<?php

namespace App\Tests\Engine;

use App\Entity\AnalysisRun;
use App\Entity\Researcher;
use App\Enum\RunStatus;
use App\Repository\AnalysisRunRepository;
use App\Service\AuditLog;
use App\Service\StalledRunReaper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * What the reaper writes on a run nobody will ever come back to. The reason text
 * is the point: a run closed by the sweep must say why, which is exactly what
 * cancelling by hand cannot record.
 */
final class StalledRunReaperTest extends TestCase
{
    /** A run shaped like a real one: the researcher relation is never null in the database. */
    private function runningRun(): AnalysisRun
    {
        return (new AnalysisRun())
            ->setResearcher((new Researcher())->setDisplayName('Michael Hrušák'))
            ->setStatus(RunStatus::RUNNING);
    }

    private function reaper(AnalysisRun ...$stalled): StalledRunReaper
    {
        $runs = $this->createStub(AnalysisRunRepository::class);
        $runs->method('findStalled')->willReturn($stalled);

        $em = $this->createStub(EntityManagerInterface::class);

        return new StalledRunReaper(
            $runs,
            $em,
            new ArrayAdapter(),
            new AuditLog($em, new NullLogger()),
            new NullLogger(),
            stallMinutes: 30,
        );
    }

    public function testClosesAStalledRunAsFailedAndSaysWhy(): void
    {
        $run = $this->runningRun()->setStartedAt(new \DateTimeImmutable('-3 hours'));

        self::assertSame(1, $this->reaper($run)->sweep());

        self::assertSame(RunStatus::FAILED, $run->getStatus());
        self::assertNotNull($run->getFinishedAt(), 'a closed run needs an end time or the history shows it open');
        self::assertStringContainsString('worker', (string) $run->getErrorMessage());
        self::assertStringContainsString('messenger:consume', (string) $run->getErrorMessage());
    }

    /** FAILED, not CANCELED: nobody decided this, and the distinction is the diagnosis. */
    public function testAStalledRunIsNotRecordedAsSomeonesDecision(): void
    {
        $run = $this->runningRun();

        $this->reaper($run)->sweep();

        self::assertNotSame(RunStatus::CANCELED, $run->getStatus());
    }

    public function testDropsAnyReportQueuedAlongsideIt(): void
    {
        $run = $this->runningRun()->setReportRequestedAt(new \DateTimeImmutable('-3 hours'));

        $this->reaper($run)->sweep();

        self::assertFalse($run->isReportPending(), 'the report was abandoned with the run');
    }

    public function testNothingStalledIsANoOp(): void
    {
        self::assertSame(0, $this->reaper()->sweep());
    }

    /** The run page reloads every 5 s; each reload must not become a write. */
    public function testTheThrottleSweepsOnceNotOnEveryCall(): void
    {
        $run = $this->runningRun();
        $reaper = $this->reaper($run);

        self::assertSame(1, $reaper->sweepThrottled());
        self::assertSame(1, $reaper->sweepThrottled(), 'second call is served from the throttle, not re-swept');
    }
}
