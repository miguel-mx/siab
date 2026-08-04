<?php

namespace App\MessageHandler;

use App\Message\GenerateReport;
use App\Repository\AnalysisRunRepository;
use App\Service\AnalysisRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Worker entry point for report writing. The model call takes tens of seconds,
 * which is why this never happens inside a web request.
 */
#[AsMessageHandler]
final class GenerateReportHandler
{
    public function __construct(
        private readonly AnalysisRunRepository $runs,
        private readonly AnalysisRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateReport $message): void
    {
        $run = $this->runs->find($message->analysisRunId);

        if ($run === null) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('AnalysisRun #%d no existe.', $message->analysisRunId)
            );
        }

        if (!$run->isReportPending()) {
            // Someone already handled it (or cancelled): nothing to write.
            $this->logger->info('Skipping report for AnalysisRun #{id}: no request in flight.', [
                'id' => $run->getId(),
            ]);

            return;
        }

        $this->runner->generateReport($run);
    }
}
