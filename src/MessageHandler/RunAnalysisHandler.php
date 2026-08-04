<?php

namespace App\MessageHandler;

use App\Message\RunAnalysis;
use App\Repository\AnalysisRunRepository;
use App\Service\AnalysisRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Worker entry point for the slow pipeline. Keeps no logic of its own: it loads
 * the run and hands it to AnalysisRunner, which owns the status transitions.
 */
#[AsMessageHandler]
final class RunAnalysisHandler
{
    public function __construct(
        private readonly AnalysisRunRepository $runs,
        private readonly AnalysisRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunAnalysis $message): void
    {
        $run = $this->runs->find($message->analysisRunId);

        if ($run === null) {
            // Deleted between dispatch and consumption — retrying cannot help.
            throw new UnrecoverableMessageHandlingException(
                sprintf('AnalysisRun #%d no existe.', $message->analysisRunId)
            );
        }

        if ($run->getStatus()->isTerminal()) {
            $this->logger->info('Skipping AnalysisRun #{id}: already {status}.', [
                'id' => $run->getId(), 'status' => $run->getStatus()->value,
            ]);

            return;
        }

        $this->runner->execute($run, $message->wantReport);
    }
}
