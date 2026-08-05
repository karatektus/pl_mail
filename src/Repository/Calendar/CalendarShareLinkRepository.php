<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\CalendarShareLink;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarShareLink>
 */
class CalendarShareLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarShareLink::class);
    }

    /**
     * The owner's list, newest first, with the calendars each covers.
     *
     * Fetch-joined because the list renders "what does this reveal, and of
     * what" on every row — a lazy collection would be one query per link, and
     * the whole screen exists to be read at a glance. Left join, so a link
     * whose every calendar has since been deleted still appears: it is still
     * live, still answers, and is exactly the row somebody needs to find in
     * order to revoke it.
     *
     * @return list<CalendarShareLink>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('calendar')
            ->leftJoin('link.calendars', 'calendar')
            ->where('link.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('link.createdAt', 'DESC')
            ->addOrderBy('link.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One link by the digest of its token — the whole of the public lookup.
     *
     * Takes the digest rather than the token, so the token never reaches a
     * repository and nothing here has to be trusted to hash it. Answers
     * revoked links too: refusing them is the reader's decision, and it wants
     * to make the same 404 for a revoked link as for an unknown one without
     * this method deciding that for it.
     *
     * findOneBy() rather than a query builder because it is a lookup on a
     * unique index and there is nothing to explain about it.
     */
    public function findOneByDigest(string $digest): ?CalendarShareLink
    {
        return $this->findOneBy(['tokenDigest' => $digest]);
    }

    /**
     * One of this user's links by id, or null.
     *
     * The ownership check is in the query rather than after it, so no caller
     * can forget it and no caller can tell "not yours" from "not there" — which
     * is the same rule the calendar repositories follow.
     */
    public function findOneForUser(User $user, int $id): ?CalendarShareLink
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }
}
