<?php

namespace App\Service;

use App\Entity\AnalysisRun;
use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Records who changed what. Callers describe the change; this decides how it is
 * written down.
 *
 * Two rules it enforces on their behalf. It persists but does not flush — the
 * entry has to land in the same transaction as the change it describes, or a
 * failed delete leaves a log saying it happened. And it never lets a logging
 * problem break the operation: an audit trail is worth a great deal, but not
 * worth refusing to deactivate a compromised account because the log is full.
 */
final class AuditLog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $details
     */
    public function forRun(AuditAction $action, AnalysisRun $run, ?User $actor, array $details = []): void
    {
        // Captured now: after a delete there is nothing left to build it from.
        // Guarded because reaching the researcher is a lazy load, and no lookup
        // failure here is worth aborting the cancel/archive/delete it describes.
        try {
            $label = sprintf('Análisis #%s (%s)', $run->getId() ?? '—', $run->getResearcher()->getDisplayName());
        } catch (\Throwable) {
            $label = sprintf('Análisis #%s', $run->getId() ?? '—');
        }

        $this->record($action, $actor, $label, $run->getId(), $details);
    }

    /**
     * @param array<string,mixed> $details
     */
    public function forUser(AuditAction $action, User $target, ?User $actor, array $details = []): void
    {
        $this->record($action, $actor, $target->getEmail(), $target->getId(), $details);
    }

    /** @param array<string,mixed> $details */
    private function record(
        AuditAction $action,
        ?User $actor,
        string $subjectLabel,
        ?int $subjectId,
        array $details,
    ): void {
        try {
            $this->em->persist(new AuditLogEntry(
                action: $action,
                actor: $actor,
                // "sistema" is not a fallback for an unknown user: it is what the
                // stalled-run sweep is, and the log should say so plainly.
                actorLabel: $actor === null ? 'sistema' : ($actor->getDisplayName() ?? $actor->getEmail()),
                subjectLabel: $subjectLabel,
                subjectId: $subjectId,
                details: $details,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Could not record audit entry {action} for {subject}: {msg}', [
                'action' => $action->value,
                'subject' => $subjectLabel,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
