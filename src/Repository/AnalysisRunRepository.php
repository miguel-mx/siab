<?php

namespace App\Repository;

use App\Doctrine\ArchivedRunFilter;
use App\Entity\AnalysisRun;
use App\Entity\Researcher;
use App\Enum\RunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisRun>
 */
class AnalysisRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalysisRun::class);
    }

    /**
     * Most recent runs across all researchers, for the dashboard's "Análisis recientes".
     *
     * @return AnalysisRun[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('r')
            ->join('a.researcher', 'r')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of the global run history, newest first, with the researcher
     * fetch-joined so the table does not issue a query per row.
     *
     * @return AnalysisRun[]
     */
    public function findHistory(
        ?string $search,
        ?RunStatus $status,
        int $page,
        int $perPage,
        bool $archivedOnly = false,
    ): array {
        $query = fn (): array => $this->historyQuery($search, $status, $archivedOnly)
            ->addSelect('r')
            ->orderBy('a.createdAt', 'DESC')
            // Runs queued in the same second would otherwise come back in an
            // arbitrary order, which makes paging skip or repeat rows.
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $archivedOnly ? $this->withArchivedVisible($query) : $query();
    }

    public function countHistory(?string $search, ?RunStatus $status, bool $archivedOnly = false): int
    {
        $query = fn (): int => (int) $this->historyQuery($search, $status, $archivedOnly)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $archivedOnly ? $this->withArchivedVisible($query) : $query();
    }

    /** How many archived runs there are, for the history screen's toggle. */
    public function countArchived(): int
    {
        return $this->withArchivedVisible(fn (): int => (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.discardedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * How many runs sit in each status, for the history screen's filter chips.
     * Honours the search but not the status filter, so the chips keep showing
     * what else the current search would match.
     *
     * @return array<string, int> status value => count
     */
    public function countsByStatus(?string $search): array
    {
        $rows = $this->historyQuery($search, null)
            ->select('a.status AS status', 'COUNT(a.id) AS total')
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            // Doctrine hydrates the enum column back into the enum case.
            $status = $row['status'];
            $counts[$status instanceof RunStatus ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }

    private function historyQuery(?string $search, ?RunStatus $status, bool $archivedOnly = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')->join('a.researcher', 'r');

        // The caller has switched the filter off to get here, so "archived" has to
        // be asked for explicitly — otherwise this would list everything at once.
        if ($archivedOnly) {
            $qb->andWhere('a.discardedAt IS NOT NULL');
        }

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('r.displayName LIKE :search OR r.orcid LIKE :search OR r.openalexId LIKE :search')
                ->setParameter('search', '%'.trim($search).'%');
        }

        if ($status !== null) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status->value);
        }

        return $qb;
    }

    /**
     * A researcher's run history, newest first.
     *
     * @return AnalysisRun[]
     */
    public function findForResearcher(Researcher $researcher): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.researcher = :researcher')
            ->setParameter('researcher', $researcher)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Total citing works examined across every finished run — a volume figure for
     * the login hero. Deliberately not the Type A share: that is an evaluation
     * metric, and the login page is the only thing an unauthenticated visitor sees.
     */
    public function sumCitationsAnalyzed(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.totalTypeA + a.totalTypeB + a.totalSelf), 0)')
            ->andWhere('a.status IN (:done)')
            ->setParameter('done', [RunStatus::COMPLETED->value])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Share of citations that are Type A — the figure that counts for SECIHTI.
     *
     * Only each researcher's newest finished run is counted: re-running someone
     * would otherwise weigh them several times. Aggregated over the totals rather
     * than averaging per-run ratios, which would give a researcher with 3 citations
     * the same weight as one with 300. Null when nothing has been counted yet.
     */
    public function typeAShare(): ?float
    {
        $latestPerResearcher = <<<'DQL'
            SELECT MAX(latest.id) FROM App\Entity\AnalysisRun latest
            WHERE latest.researcher = a.researcher AND latest.status IN (:done)
            DQL;

        $row = $this->createQueryBuilder('a')
            ->select('SUM(a.totalTypeA) AS typeA, SUM(a.totalTypeA + a.totalTypeB + a.totalSelf) AS total')
            ->andWhere('a.status IN (:done)')
            ->andWhere(sprintf('a.id = (%s)', $latestPerResearcher))
            ->setParameter('done', [RunStatus::COMPLETED->value])
            ->getQuery()
            ->getSingleResult();

        $total = (int) ($row['total'] ?? 0);

        return $total > 0 ? (int) $row['typeA'] / $total : null;
    }

    /**
     * Runs per calendar month for the KPI sparkline, oldest first, gaps filled
     * with zeros so the line has one point per month.
     *
     * @return list<array{label:string, count:int}>
     */
    public function countsByMonth(int $months = 8): array
    {
        $first = new \DateTimeImmutable(sprintf('first day of -%d months midnight', $months - 1));

        // Bucketed in PHP: grouping by month in DQL needs a vendor-specific date
        // function, and the window holds at most a few hundred rows.
        $rows = $this->createQueryBuilder('a')
            ->select('a.createdAt')
            ->andWhere('a.createdAt >= :first')
            ->setParameter('first', $first)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $key = $row['createdAt']->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $first->modify(sprintf('+%d months', $i));
            $key = $month->format('Y-m');
            $series[] = ['label' => $key, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $series;
    }

    /**
     * The most recent completed run for a researcher — used as the "prior"
     * snapshot for the report's comparison section.
     */
    public function findLatestCompletedForResearcher(Researcher $researcher): ?AnalysisRun
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.researcher = :researcher')
            ->andWhere('a.status IN (:done)')
            ->setParameter('researcher', $researcher)
            ->setParameter('done', ['completed'])
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The researcher's newest finished run from *before* this one — what the
     * report's "comparación con el análisis anterior" section is written from.
     *
     * Scoped to runs older than the given id rather than "the newest", because the
     * run asking is usually the newest itself and would otherwise compare against
     * its own figures. Archived runs are excluded by the filter, which is right: a
     * run hidden from the history should not be quoted back in a report.
     */
    public function findPreviousCompletedForResearcher(Researcher $researcher, ?int $beforeId): ?AnalysisRun
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.researcher = :researcher')
            ->andWhere('a.status IN (:done)')
            ->setParameter('researcher', $researcher)
            ->setParameter('done', [RunStatus::COMPLETED->value])
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1);

        if ($beforeId !== null) {
            $qb->andWhere('a.id < :before')->setParameter('before', $beforeId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * A run by slug, archived or not — an archived analysis stays readable at its
     * URL, which is the point of archiving instead of deleting. Everything else
     * goes through the filter and never sees them.
     */
    public function findBySlugIncludingArchived(string $slug): ?AnalysisRun
    {
        return $this->withArchivedVisible(fn (): ?AnalysisRun => $this->findOneBy(['slug' => $slug]));
    }

    /**
     * Run a query with archived runs visible, then put the filter back however it
     * was — the history screen's "archivados" view needs this, and so does anything
     * that must not silently depend on the caller's filter state.
     *
     * @template T
     *
     * @param callable(): T $query
     *
     * @return T
     */
    public function withArchivedVisible(callable $query): mixed
    {
        $filters = $this->getEntityManager()->getFilters();
        $wasEnabled = $filters->isEnabled(ArchivedRunFilter::NAME);

        if ($wasEnabled) {
            $filters->disable(ArchivedRunFilter::NAME);
        }

        try {
            return $query();
        } finally {
            if ($wasEnabled) {
                $filters->enable(ArchivedRunFilter::NAME);
            }
        }
    }

    /**
     * Runs still marked RUNNING long after a worker should have finished them.
     * See StalledRunReaper for why the cutoff can be trusted.
     *
     * @return AnalysisRun[]
     */
    public function findStalled(\DateTimeImmutable $startedBefore): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :running')
            ->andWhere('a.startedAt IS NOT NULL')
            ->andWhere('a.startedAt < :cutoff')
            ->setParameter('running', RunStatus::RUNNING->value)
            ->setParameter('cutoff', $startedBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * How long a finished analysis usually takes, for the "va por…" estimate on a
     * run in flight. The median, not the mean: one 40-minute outlier for a prolific
     * author should not move what a typical run promises.
     */
    public function medianDurationSeconds(): ?int
    {
        $durations = $this->createQueryBuilder('a')
            ->select('a.startedAt', 'a.finishedAt')
            ->andWhere('a.status IN (:done)')
            ->andWhere('a.startedAt IS NOT NULL')
            ->andWhere('a.finishedAt IS NOT NULL')
            ->setParameter('done', ['completed'])
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getArrayResult();

        $seconds = array_map(
            static fn (array $r): int => $r['finishedAt']->getTimestamp() - $r['startedAt']->getTimestamp(),
            $durations,
        );
        $seconds = array_values(array_filter($seconds, static fn (int $s) => $s > 0));

        if ($seconds === []) {
            return null;
        }

        sort($seconds);

        return $seconds[intdiv(count($seconds), 2)];
    }
}
