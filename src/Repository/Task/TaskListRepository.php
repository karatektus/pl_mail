<?php

declare(strict_types=1);

namespace App\Repository\Task;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskList>
 */
class TaskListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskList::class);
    }

    public function findDefaultForUser(User $user): ?TaskList
    {
        return $this->findOneBy(['usr' => $user, 'isDefault' => true]);
    }

    /**
     * Every list of one user, in the order they arranged in Lists. Before anything
     * was arranged every list has the same place, and the first list leads.
     *
     * @return list<TaskList>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['usr' => $user], ['sortOrder' => 'ASC', 'isDefault' => 'DESC', 'name' => 'ASC']);
    }

    /** The place after the last, for a list made now: it joins at the end rather than above an arranged order. */
    public function nextSortOrder(User $user): int
    {
        return 1 + (int) $this->createQueryBuilder('list')
            ->select('COALESCE(MAX(list.sortOrder), -1)')
            ->where('list.usr = :usr')
            ->setParameter('usr', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * What the sweep syncs: mirrors not read since $before and not backing off,
     * longest waiting first. The epoch puts a list never synced at the front. A
     * list of an account whose Tasks switch is off waits, as AccountService says.
     *
     * @return list<TaskList>
     */
    public function findDueForSync(DateTimeImmutable $before, int $limit = 200, ?DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('list')
            ->addSelect('COALESCE(list.lastSyncedAt, :epoch) AS HIDDEN dueSince')
            ->leftJoin('list.account', 'account')
            ->where('list.remoteId IS NOT NULL')
            ->andWhere('list.account IS NULL OR account.tasksEnabled = true')
            ->andWhere('list.lastSyncedAt IS NULL OR list.lastSyncedAt < :before')
            ->andWhere('list.syncBackoffUntil IS NULL OR list.syncBackoffUntil <= :now')
            ->setParameter('before', $before)
            ->setParameter('now', $now ?? new DateTimeImmutable())
            ->setParameter('epoch', new DateTimeImmutable('@0'))
            ->orderBy('dueSince', 'ASC')
            ->addOrderBy('list.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The mirrors of one source, by remote id.
     *
     * @return array<string, TaskList>
     */
    public function findForSource(CalendarSource $source): array
    {
        $criteria = null !== $source->integration
            ? ['integration' => $source->integration]
            : ['account' => $source->account];

        $mirrored = [];

        foreach ($this->findBy($criteria) as $list) {
            $mirrored[(string) $list->remoteId] = $list;
        }

        return $mirrored;
    }

    /** One of this user's lists by id, or null: a list of someone else's is not there. */
    public function findOwned(User $user, int $id): ?TaskList
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }
}
