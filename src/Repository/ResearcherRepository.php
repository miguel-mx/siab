<?php

namespace App\Repository;

use App\Entity\Researcher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Researcher>
 */
class ResearcherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Researcher::class);
    }

    /**
     * The roster ordered for the dashboard: name A→Z.
     *
     * @return Researcher[]
     */
    public function findRoster(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Roster rows with their run stats: how many analyses each researcher has and
     * when the last one was launched. One query, no N+1.
     *
     * @param string|null $search Free text matched against name, ORCID and OpenAlex ID.
     *
     * @return list<array{researcher:Researcher, runs:int, lastRun:?\DateTimeImmutable}>
     */
    public function findRosterWithStats(?int $limit = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r AS researcher', 'COUNT(a.id) AS runs', 'MAX(a.createdAt) AS lastRun')
            ->leftJoin('r.analyses', 'a')
            ->groupBy('r.id')
            // Most recently analyzed first; never-analyzed researchers at the end.
            ->orderBy('lastRun', 'DESC')
            ->addOrderBy('r.displayName', 'ASC');

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('r.displayName LIKE :search OR r.orcid LIKE :search OR r.openalexId LIKE :search OR r.field LIKE :search')
                ->setParameter('search', '%'.trim($search).'%');
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return array_map(static fn (array $row) => [
            'researcher' => $row['researcher'],
            'runs' => (int) $row['runs'],
            'lastRun' => $row['lastRun'] !== null ? new \DateTimeImmutable($row['lastRun']) : null,
        ], $qb->getQuery()->getResult());
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** How many of the roster have at least one analysis on record. */
    public function countWithAnalyses(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->join('r.analyses', 'a')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByOrcid(string $orcid): ?Researcher
    {
        return $this->findOneBy(['orcid' => Researcher::normalizeOrcid($orcid)]);
    }

    public function findOneByOpenalexId(string $openalexId): ?Researcher
    {
        return $this->findOneBy(['openalexId' => Researcher::normalizeOpenalexId($openalexId)]);
    }
}
