<?php

namespace App\Service;

use App\Enum\AuditAction;
use App\Enum\RunStatus;
use App\Repository\AnalysisRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Closes runs whose worker never came back.
 *
 * This exists because cancelling cannot cover the case: cancelling is something a
 * person does, and a run abandoned by a dead worker may sit RUNNING for days
 * before anyone opens the page. Only the worker ever writes a terminal status, so
 * when the worker is gone nothing else will.
 *
 * These are recorded as FAILED, not CANCELED, and with the reason spelled out.
 * "Cancelado" would claim someone decided to stop it and would say nothing about
 * what went wrong — which is the whole point of noticing.
 *
 * The threshold is safe by construction: config/packages/http_client.yaml caps a
 * single /analyze at max_duration 900s, so a worker that is genuinely alive has
 * always resolved one way or the other well before this fires.
 */
final class StalledRunReaper
{
    private const THROTTLE_KEY = 'siab.stalled_run_sweep';

    public function __construct(
        private readonly AnalysisRunRepository $runs,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(int:RUN_STALL_MINUTES)%')]
        private readonly int $stallMinutes = 30,
    ) {
    }

    /**
     * Sweep, but at most once a minute however often this is called — the run page
     * refreshes itself every 5 seconds, and every one of those would otherwise be a
     * write query.
     */
    public function sweepThrottled(): int
    {
        return (int) $this->cache->get(self::THROTTLE_KEY, function (ItemInterface $item): int {
            $item->expiresAfter(60);

            return $this->sweep();
        });
    }

    /** @return int how many runs were closed */
    public function sweep(): int
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d minutes', $this->stallMinutes));
        $stalled = $this->runs->findStalled($cutoff);

        foreach ($stalled as $run) {
            $run->setStatus(RunStatus::FAILED)
                ->setFinishedAt(new \DateTimeImmutable())
                ->setErrorMessage(sprintf(
                    'El worker dejó de responder: el análisis llevaba más de %d minutos en proceso sin terminar. '
                    .'Comprueba que un worker esté consumiendo la cola (php bin/console messenger:consume async) '
                    .'y vuelve a lanzarlo.',
                    $this->stallMinutes,
                ))
                // Anything queued alongside it is equally abandoned.
                ->setReportRequestedAt(null);

            // Actor null on purpose: nobody did this, and the log says "sistema"
            // rather than inventing a person for it.
            $this->audit->forRun(AuditAction::RUN_REAPED, $run, null, [
                'minutos' => $this->stallMinutes,
            ]);

            $this->logger->warning('AnalysisRun #{id} closed as stalled after {min} min.', [
                'id' => $run->getId(), 'min' => $this->stallMinutes,
            ]);
        }

        if ($stalled !== []) {
            $this->em->flush();
        }

        return count($stalled);
    }
}
