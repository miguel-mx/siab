<?php

namespace App\Service;

use App\Engine\AnalysisResultMapper;
use App\Engine\CitationEngineClient;
use App\Engine\CitationEngineException;
use App\Engine\Dto\AuthorDto;
use App\Entity\AnalysisRun;
use App\Entity\Researcher;
use App\Entity\User;
use App\Enum\AnalysisSource;
use App\Enum\AuditAction;
use App\Enum\RunStatus;
use App\Message\GenerateReport;
use App\Message\RunAnalysis;
use App\Repository\AnalysisRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Owns the AnalysisRun lifecycle: `queue()` creates the row a controller can
 * redirect to, `execute()` is what the worker runs (engine → mapper → persist).
 * Status transitions live here and nowhere else.
 */
final class AnalysisRunner
{
    public function __construct(
        private readonly AnalysisRunRepository $runs,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly CitationEngineClient $engine,
        private readonly AnalysisResultMapper $mapper,
        private readonly SlugGenerator $slugs,
        private readonly LoggerInterface $logger,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Persist a QUEUED run without dispatching it — for callers that will drive
     * execute() themselves (CLI, tests). Web requests want queue() instead.
     */
    public function create(
        Researcher $researcher,
        ?User $owner = null,
        string $reportLanguage = 'es',
        ?array $sources = null,
    ): AnalysisRun {
        $run = (new AnalysisRun())
            ->setResearcher($researcher)
            ->setOwner($owner)
            ->setReportLanguage($reportLanguage)
            ->setStatus(RunStatus::QUEUED);

        // Recorded on the run, not just sent to the engine: which sources a figure
        // came from is part of reading it, and until now the column said "openalex"
        // for every run regardless of what was actually queried.
        if ($sources !== null) {
            $run->setSources(implode(',', $sources));
        }
        $run->setSlug($this->slugs->forRun($run));

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /**
     * Persist a QUEUED run and hand it to the async transport. Returns immediately;
     * the heavy work happens in a worker (see RunAnalysisHandler).
     */
    public function queue(
        Researcher $researcher,
        ?User $owner = null,
        bool $wantReport = false,
        string $reportLanguage = 'es',
        ?array $sources = null,
    ): AnalysisRun {
        $run = $this->create($researcher, $owner, $reportLanguage, $sources);

        $this->bus->dispatch(new RunAnalysis($run->getId(), $wantReport));

        $this->logger->info('Queued AnalysisRun #{id} for researcher #{researcher}.', [
            'id' => $run->getId(), 'researcher' => $researcher->getId(),
        ]);

        return $run;
    }

    /**
     * Run the pipeline synchronously for an existing run. Terminal state on return:
     * COMPLETED (with or without warnings) or FAILED.
     *
     * Engine failures are recorded on the run rather than rethrown: /analyze takes
     * minutes, so a retry storm against a down engine buys nothing — the run shows
     * FAILED with the engine's message and can be relaunched. Unexpected errors
     * (bugs) are rethrown after being recorded, so they land in the failed queue.
     */
    public function execute(AnalysisRun $run, bool $wantReport = false): AnalysisRun
    {
        $researcher = $run->getResearcher();
        $query = $researcher->preferredEngineQuery() ?? $researcher->getDisplayName();

        $run->setStatus(RunStatus::RUNNING)
            ->setStartedAt(new \DateTimeImmutable())
            ->setErrorMessage(null);
        $this->em->flush();

        try {
            $result = $this->engine->analyze(
                authorId: $query,
                wantReport: $wantReport,
                reportLanguage: $run->getReportLanguage(),
                // Carries this researcher's Scopus AU-ID / zbMATH code / INSPIRE recid,
                // so each source is queried for the right person.
                researcher: $researcher,
                // The key the run's page polls the engine with while this call is
                // open — see CitationEngineClient::progress().
                jobId: (string) $run->getId(),
                // What the person chose when they launched it, read back from the
                // run so a relaunch uses the same sources as the original.
                sources: array_map(
                    static fn (AnalysisSource $s): string => $s->value,
                    AnalysisSource::parseList($run->getSources()),
                ),
                // This run's figures will come out under the current rule.
                comparison: $wantReport ? $this->previousFigures($run, AnalysisRun::CLASSIFICATION_RULE) : null,
            );

            // Someone may have cancelled while we were inside /analyze; that request
            // could not be called back, but its result is not wanted.
            if ($this->wasCanceledMeanwhile($run)) {
                return $run;
            }

            $this->discardPreviousResults($run);
            $this->mapper->apply($run, $result);
            $this->syncResearcher($researcher, $result->author);

            // Warnings do not change the status: they qualify the figures, and the
            // run is finished either way. They travel on the run and are shown there.
            $run->setStatus(RunStatus::COMPLETED)
                ->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->logger->info(
                'AnalysisRun #{id} {status}: {articles} artículos, A/B/self = {a}/{b}/{self}.',
                [
                    'id' => $run->getId(),
                    'status' => $run->getStatus()->value,
                    'articles' => $run->getTotalArticles(),
                    'a' => $run->getTotalTypeA(),
                    'b' => $run->getTotalTypeB(),
                    'self' => $run->getTotalSelf(),
                ]
            );

            return $run;
        } catch (CitationEngineException $e) {
            $this->markFailed($run, $this->describe($e));

            return $run;
        } catch (\Throwable $e) {
            $this->markFailed($run, sprintf('Error interno al procesar el análisis: %s', $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Stop a queued or running analysis.
     *
     * What this can and cannot do is worth being precise about. A QUEUED run is
     * genuinely stopped: RunAnalysisHandler skips terminal runs, so the message is
     * discarded whenever a worker eventually picks it up. A RUNNING one is a claim
     * on the *result*, not on the process — the worker is blocked inside a
     * /analyze call that may take minutes and cannot be interrupted from here — so
     * execute() re-reads the status afterwards and throws its answer away.
     *
     * It also covers the case this was written for: a run left RUNNING forever
     * because the worker died mid-flight. Nothing else will ever move that row.
     */
    public function cancel(AnalysisRun $run, ?User $actor = null): bool
    {
        if (!$run->getStatus()->isCancellable()) {
            return false;
        }

        $was = $run->getStatus();

        $run->setStatus(RunStatus::CANCELED)
            ->setFinishedAt(new \DateTimeImmutable())
            ->setErrorMessage(sprintf('Cancelado%s.', $actor !== null ? ' por '.$this->name($actor) : ''))
            // A report queued alongside the analysis has nothing to write from.
            ->setReportRequestedAt(null);

        $this->audit->forRun(AuditAction::RUN_CANCELED, $run, $actor, ['estado_previo' => $was->value]);
        $this->em->flush();

        $this->logger->info('AnalysisRun #{id} canceled by {by}.', [
            'id' => $run->getId(), 'by' => $actor?->getEmail() ?? 'desconocido',
        ]);

        return true;
    }

    private function name(User $user): string
    {
        return $user->getDisplayName() ?? $user->getEmail();
    }

    /**
     * Hide a finished analysis from the history and from every aggregate, without
     * losing it. The row and its articles stay exactly as they were, so a report
     * already published from these figures can still be reproduced.
     */
    public function archive(AnalysisRun $run, ?User $actor = null): bool
    {
        if (!$run->isArchivable()) {
            return false;
        }

        $run->setDiscardedAt(new \DateTimeImmutable());
        $this->audit->forRun(AuditAction::RUN_ARCHIVED, $run, $actor);
        $this->em->flush();

        $this->logger->info('AnalysisRun #{id} archived by {by}.', [
            'id' => $run->getId(), 'by' => $actor?->getEmail() ?? 'desconocido',
        ]);

        return true;
    }

    public function restore(AnalysisRun $run, ?User $actor = null): bool
    {
        if (!$run->isArchived()) {
            return false;
        }

        $run->setDiscardedAt(null);
        $this->audit->forRun(AuditAction::RUN_RESTORED, $run, $actor);
        $this->em->flush();

        $this->logger->info('AnalysisRun #{id} restored by {by}.', [
            'id' => $run->getId(), 'by' => $actor?->getEmail() ?? 'desconocido',
        ]);

        return true;
    }

    /**
     * Permanently remove a run that produced nothing — a failed or cancelled
     * attempt. Its articles and their citing works go with it (orphanRemoval), and
     * because such a run is in no aggregate, no figure anywhere changes.
     *
     * Runs *with* figures are refused here on purpose: deleting one would silently
     * move the dashboard's Type A share and a researcher's current numbers. Those
     * get archived.
     */
    public function delete(AnalysisRun $run, ?User $actor = null): bool
    {
        if (!$run->isDeletable()) {
            return false;
        }

        $id = $run->getId();

        // Written before the row goes, and flushed with the delete: the entry keeps
        // the run's id, researcher and final state, which is all that will be left.
        $this->audit->forRun(AuditAction::RUN_DELETED, $run, $actor, [
            'estado' => $run->getStatus()->value,
            'motivo' => $run->getErrorMessage(),
        ]);

        $this->em->remove($run);
        $this->em->flush();

        $this->logger->notice('AnalysisRun #{id} deleted by {by}.', [
            'id' => $id, 'by' => $actor?->getEmail() ?? 'desconocido',
        ]);

        return true;
    }

    /**
     * Queue a narrative report for a run that already has figures. Cheap and
     * OpenAlex-free: the engine writes it from `rawSnapshot`, the exact payload it
     * produced during the analysis.
     */
    public function queueReport(AnalysisRun $run, ?string $language = null): void
    {
        if ($language !== null) {
            $run->setReportLanguage($language);
        }

        $run->setReportRequestedAt(new \DateTimeImmutable())->setReportError(null);
        $this->em->flush();

        $this->bus->dispatch(new GenerateReport($run->getId()));

        $this->logger->info('Queued report for AnalysisRun #{id} ({lang}).', [
            'id' => $run->getId(), 'lang' => $run->getReportLanguage(),
        ]);
    }

    /**
     * Write the report now (worker side). Failures are recorded on the run — the
     * analysis itself stays valid, only its prose is missing.
     */
    public function generateReport(AnalysisRun $run): void
    {
        $snapshot = $run->getRawSnapshot();

        if ($snapshot === null) {
            $this->finishReport($run, error: 'El análisis no conserva el resultado del motor; vuelve a ejecutarlo.');

            return;
        }

        try {
            $response = $this->engine->report(
                $snapshot,
                $run->getReportLanguage(),
                $this->previousFigures($run, $run->getClassificationRule()),
            );
            $report = trim((string) ($response['report'] ?? ''));

            if ($report === '') {
                $this->finishReport($run, error: 'El motor devolvió un informe vacío.');

                return;
            }

            $run->setReport($report);
            $this->finishReport($run);

            $this->logger->info('Report written for AnalysisRun #{id} ({chars} caracteres).', [
                'id' => $run->getId(), 'chars' => mb_strlen($report),
            ]);
        } catch (CitationEngineException $e) {
            $this->finishReport($run, error: $this->describe($e));
        }
    }

    /**
     * Re-read the row the cancel button writes to. The entity has been in memory
     * for the whole /analyze call, so its status is by definition stale by now;
     * nothing local is pending, which makes a refresh safe.
     */
    private function wasCanceledMeanwhile(AnalysisRun $run): bool
    {
        try {
            $this->em->refresh($run);
        } catch (\Throwable $e) {
            // Row vanished, or the connection is gone. Not a reason to lose a
            // result that took minutes to compute — carry on and let the flush say.
            $this->logger->warning('Could not re-read AnalysisRun #{id} before persisting: {msg}', [
                'id' => $run->getId(), 'msg' => $e->getMessage(),
            ]);

            return false;
        }

        if ($run->getStatus() !== RunStatus::CANCELED) {
            return false;
        }

        $this->logger->info('Discarding the result of AnalysisRun #{id}: canceled while the engine was working.', [
            'id' => $run->getId(),
        ]);

        return true;
    }

    /**
     * The researcher's previous finished run, as the figures the report compares
     * against — or null when there genuinely is no earlier one.
     *
     * Only SIAB knows an analysis has a history; the engine sees one result at a
     * time. Without this the report had nothing to compare and said so on every
     * run, for every researcher.
     *
     * Also null when the previous run's figures were classified under another rule
     * than `$rule` (the one the report's own figures follow): a drop in Type B that
     * only reflects the rule change must not be written up as a change in the
     * researcher's record. Omitting the comparison is what the prompt already
     * handles — it then says nothing about earlier analyses.
     *
     * @return array<string,mixed>|null
     */
    private function previousFigures(AnalysisRun $run, ?string $rule): ?array
    {
        $previous = $this->runs->findPreviousCompletedForResearcher($run->getResearcher(), $run->getId());

        if ($previous === null || $previous->getClassificationRule() !== $rule) {
            return null;
        }

        return [
            'fecha' => ($previous->getFinishedAt() ?? $previous->getCreatedAt())->format('Y-m-d'),
            'total_articulos' => $previous->getTotalArticles(),
            'total_citas' => $previous->getTotalCitations(),
            'total_citas_tipo_a' => $previous->getTotalTypeA(),
            'total_citas_tipo_b' => $previous->getTotalTypeB(),
            'autocitas' => $previous->getTotalSelf(),
        ];
    }

    /** Clear the in-flight marker, recording the reason when it failed. */
    private function finishReport(AnalysisRun $run, ?string $error = null): void
    {
        if ($error !== null) {
            $this->logger->error('Report for AnalysisRun #{id} failed: {msg}', [
                'id' => $run->getId(), 'msg' => $error,
            ]);
        }

        $run->setReportRequestedAt(null)->setReportError($error);
        $this->em->flush();
    }

    /**
     * Drop the articles of a previous attempt so a relaunched run cannot double-count.
     * orphanRemoval on AnalysisRun::$articles deletes them (and their citing works).
     */
    private function discardPreviousResults(AnalysisRun $run): void
    {
        if ($run->getArticles()->isEmpty()) {
            return;
        }

        foreach ($run->getArticles()->toArray() as $article) {
            $run->removeArticle($article);
        }

        $run->setTotalArticles(0)->setTotalTypeA(0)->setTotalTypeB(0)->setTotalSelf(0);
        $this->em->flush();
    }

    /**
     * Backfill the roster from what the engine resolved. Never overwrites an id the
     * user curated — only fills gaps — and stores bare ids, not the engine's URLs.
     */
    private function syncResearcher(Researcher $researcher, AuthorDto $author): void
    {
        if ($researcher->getOpenalexId() === null) {
            $researcher->setOpenalexId(Researcher::normalizeOpenalexId($author->openalexId));
        }
        if ($researcher->getOrcid() === null && $author->orcid !== null) {
            $researcher->setOrcid(Researcher::normalizeOrcid($author->orcid));
        }

        $researcher->setWorksCount($author->worksCount)->touch();
    }

    private function markFailed(AnalysisRun $run, string $message): void
    {
        $this->logger->error('AnalysisRun #{id} failed: {msg}', [
            'id' => $run->getId(), 'msg' => $message,
        ]);

        $run->setStatus(RunStatus::FAILED)
            ->setErrorMessage($message)
            ->setFinishedAt(new \DateTimeImmutable());

        // A Doctrine-level failure closes the manager; then the row keeps its
        // RUNNING status and only the log records the cause. Never mask the
        // original error with a second one.
        if (!$this->em->isOpen()) {
            return;
        }

        try {
            $this->em->flush();
        } catch (\Throwable $flushError) {
            $this->logger->critical('Could not persist FAILED status for AnalysisRun #{id}: {msg}', [
                'id' => $run->getId(), 'msg' => $flushError->getMessage(),
            ]);
        }
    }

    private function describe(CitationEngineException $e): string
    {
        return $e->detail !== null
            ? sprintf('%s (%s)', $e->getMessage(), $e->detail)
            : $e->getMessage();
    }
}
