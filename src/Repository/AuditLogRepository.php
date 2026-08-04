<?php

namespace App\Repository;

use App\Entity\AuditLogEntry;
use App\Enum\AuditAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * One page of the log, newest first.
     *
     * @return AuditLogEntry[]
     */
    public function findPage(?string $subjectType, int $page, int $perPage): array
    {
        return $this->pageQuery($subjectType)
            ->addSelect('u')
            ->leftJoin('e.actor', 'u')
            ->orderBy('e.occurredAt', 'DESC')
            // Entries written in the same second need a stable tiebreak, or paging
            // repeats and skips rows.
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countPage(?string $subjectType): int
    {
        return (int) $this->pageQuery($subjectType)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function pageQuery(?string $subjectType): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');

        if ($subjectType !== null) {
            $qb->andWhere('e.subjectType = :type')->setParameter('type', $subjectType);
        }

        return $qb;
    }

    /**
     * The most recent entry of one kind about one thing — "archivado por X el Y",
     * shown on the run's own page.
     */
    public function latestFor(AuditAction $action, int $subjectId): ?AuditLogEntry
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.action = :action')
            ->andWhere('e.subjectType = :type')
            ->andWhere('e.subjectId = :id')
            ->setParameter('action', $action->value)
            ->setParameter('type', $action->subjectType())
            ->setParameter('id', $subjectId)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recent entries about one account, for the row on the accounts screen.
     *
     * @return AuditLogEntry[]
     */
    public function findForUser(int $userId, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.subjectType = :type')
            ->andWhere('e.subjectId = :id')
            ->setParameter('type', 'user')
            ->setParameter('id', $userId)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
