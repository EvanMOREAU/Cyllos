<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * @return ActivityLog[]
     */
    public function findRecent(int $limit = 100, int $offset = 0, bool $includeHelloAssoCalls = true): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!$includeHelloAssoCalls) {
            $qb->andWhere('l.action != :helloAssoAction')
                ->setParameter('helloAssoAction', 'api.helloasso');
        }

        return $qb->getQuery()->getResult();
    }

    public function deleteAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes outbound API call traces (action "api.*") older than the given
     * date — the bulk of the table, and the least useful to keep long-term.
     */
    public function deleteApiCallsOlderThan(\DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.createdAt < :date')
            ->andWhere('l.action LIKE :apiPrefix')
            ->setParameter('date', $date)
            ->setParameter('apiPrefix', 'api.%')
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes every log row (audit lines included) older than the given date.
     */
    public function deleteOlderThan(\DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }

    /**
     * @return array{items: ActivityLog[], total: int, page: int, perPage: int, pageCount: int}
     */
    public function paginate(int $page, int $perPage, bool $includeHelloAssoCalls = true): array
    {
        $page = max(1, $page);

        $countQb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)');

        if (!$includeHelloAssoCalls) {
            $countQb->andWhere('l.action != :helloAssoAction')
                ->setParameter('helloAssoAction', 'api.helloasso');
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $items = $this->findRecent($perPage, ($page - 1) * $perPage, $includeHelloAssoCalls);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
