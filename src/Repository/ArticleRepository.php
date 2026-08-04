<?php

namespace App\Repository;

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

    public function countForRun(AnalysisRun $run, ?string $search = null): int
    {
        return (int) $this->scoped($this->createQueryBuilder('a')->select('COUNT(a.id)'), $run, $search)
            ->getQuery()
            ->getSingleScalarResult();
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
    ): array {
        $qb = $this->scoped($this->createQueryBuilder('a'), $run, $search);

        return ($sort ?? ArticleSort::CITED)->applyTo($qb)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
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
     * someone would actually recognise a paper by. Shared by the count and the page
     * so both always describe the same set — a search that filtered the rows but not
     * the count would page into emptiness.
     */
    private function scoped(QueryBuilder $qb, AnalysisRun $run, ?string $search): QueryBuilder
    {
        $qb->andWhere('a.analysisRun = :run')->setParameter('run', $run);

        $search = trim((string) $search);

        if ($search !== '') {
            $qb->andWhere('a.title LIKE :q OR a.journal LIKE :q OR a.doi LIKE :q')
                ->setParameter('q', '%'.$search.'%');
        }

        return $qb;
    }
}
