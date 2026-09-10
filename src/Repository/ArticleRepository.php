<?php

namespace App\Repository;

use App\Analysis\RunFigures;
use App\Entity\AnalysisRun;
use App\Entity\Article;
use App\Enum\ArticleSort;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function countForRun(AnalysisRun $run, ?string $search = null, bool $excludePreprints = false): int
    {
        return (int) $this->scoped($this->createQueryBuilder('a')->select('COUNT(a.id)'), $run, $search, $excludePreprints)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The run's headline figures re-derived from the articles, so they describe the
     * same set the list does. Summed in the database rather than over the hydrated
     * page: the page holds 25 articles and these totals are over all of them.
     *
     * The search is deliberately not applied. Searching narrows what you are looking
     * at; it does not change what the analysis found, and recomputing the tiles for
     * "hrusak" would turn a filter into a different set of official-looking numbers.
     */
    public function figuresForRun(AnalysisRun $run, bool $excludePreprints): RunFigures
    {
        if (!$excludePreprints) {
            return RunFigures::stored($run);
        }

        $row = $this->scoped(
            $this->createQueryBuilder('a')->select(
                'COUNT(a.id) AS articles',
                'COALESCE(SUM(a.citesTypeA), 0) AS a_count',
                'COALESCE(SUM(a.citesTypeB), 0) AS b_count',
                'COALESCE(SUM(a.citesSelf), 0) AS self_count',
            ),
            $run,
            null,
            excludePreprints: true,
        )->getQuery()->getSingleResult();

        return new RunFigures(
            (int) $row['articles'],
            (int) $row['a_count'],
            (int) $row['b_count'],
            (int) $row['self_count'],
        );
    }

    /**
     * The run's preprints counted per repository — "arXiv 17, bioRxiv 1".
     *
     * What the filter hides has to be nameable, or hiding it is just a smaller
     * number with no account of what left. A preprint whose repository the engine
     * could not name still counts; it is keyed under an empty string and the
     * template says so rather than dropping it.
     *
     * @return array<string,int> repository => count, largest first
     */
    public function preprintCountsByRepository(AnalysisRun $run): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('COALESCE(a.repository, :unknown) AS repositorio', 'COUNT(a.id) AS total')
            ->andWhere('a.analysisRun = :run')
            ->andWhere('a.workType = :preprint')
            ->setParameter('run', $run)
            ->setParameter('preprint', Article::TYPE_PREPRINT)
            ->setParameter('unknown', '')
            ->groupBy('repositorio')
            ->orderBy('total', 'DESC')
            ->addOrderBy('repositorio', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map('intval', array_column($rows, 'total', 'repositorio'));
    }

    /**
     * One page of a run's articles — without their citing works.
     *
     * The citations are the bulk of a run (165 articles against 1,467 citations for
     * a prolific author, and the most-cited article alone has 86), so they are
     * fetched only when someone opens one: see AnalysisController::articleCitations.
     *
     * @return Article[] most-cited first
     */
    public function findPageForRun(
        AnalysisRun $run,
        int $page = 1,
        int $perPage = 25,
        ?ArticleSort $sort = null,
        ?string $search = null,
        bool $excludePreprints = false,
    ): array {
        $qb = $this->scoped($this->createQueryBuilder('a'), $run, $search, $excludePreprints);

        return ($sort ?? ArticleSort::CITED)->applyTo($qb)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * A run's articles with every citing work attached, most-cited first.
     *
     * The one place the whole graph is wanted at once: the XLSX export writes each
     * citation as its own row, so there is nothing to defer. Fetch-joined because
     * the alternative is one query per article — ~165 of them for a prolific
     * author, against a page that already holds them all in memory anyway.
     *
     * @return Article[]
     */
    public function findAllForRunWithCitations(AnalysisRun $run, bool $excludePreprints = false): array
    {
        $qb = $this->scoped($this->createQueryBuilder('a'), $run, null, $excludePreprints)
            ->addSelect('cw')
            ->leftJoin('a.citingWorks', 'cw');

        return ArticleSort::CITED->applyTo($qb)->getQuery()->getResult();
    }

    /**
     * How many citing works each of these articles has, for the "Ver N citas"
     * labels — one grouped query for the page instead of loading the citations
     * themselves just to count them.
     *
     * Counted rather than derived from the article's A/B/self totals: when the
     * engine flags a classification mismatch those totals and the stored citations
     * disagree, and the label must describe what opening it will actually show.
     *
     * @param list<int> $ids
     *
     * @return array<int,int> article id => count
     */
    public function citingWorkCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(cw.article) AS id, COUNT(cw.id) AS total
             FROM App\Entity\CitingWork cw
             WHERE cw.article IN (:ids)
             GROUP BY cw.article'
        )->setParameter('ids', $ids)->getArrayResult();

        return array_column($rows, 'total', 'id');
    }

    /**
     * The run's articles, optionally narrowed by a free-text search over the fields
     * someone would actually recognise a paper by, and optionally with preprints
     * hidden. Shared by the count, the page and the figures so all three always
     * describe the same set — a search that filtered the rows but not the count would
     * page into emptiness, and tiles over a different set would just be wrong.
     */
    private function scoped(
        QueryBuilder $qb,
        AnalysisRun $run,
        ?string $search,
        bool $excludePreprints = false,
    ): QueryBuilder {
        $qb->andWhere('a.analysisRun = :run')->setParameter('run', $run);

        if ($excludePreprints) {
            // IS NULL is kept deliberately: a null work_type means the record reached
            // us from a source that reports no type (anything but OpenAlex), and
            // hiding those would silently drop real papers under a preprint filter.
            // Parenthesised in the string rather than trusting andWhere() to do it:
            // without them the OR would bind looser than the run condition above and
            // the page would show every run's non-preprints.
            $qb->andWhere('(a.workType IS NULL OR a.workType <> :preprint)')
                ->setParameter('preprint', Article::TYPE_PREPRINT);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $qb->andWhere('a.title LIKE :q OR a.journal LIKE :q OR a.doi LIKE :q')
                ->setParameter('q', '%'.$search.'%');
        }

        return $qb;
    }
}
