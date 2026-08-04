<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    public function findOneForUser(UserInterface $user, int $id): ?CalendarEvent
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    /** The identity a later message updates or cancels against. */
    public function findOneByUid(Calendar $calendar, string $uid): ?CalendarEvent
    {
        return $this->findOneBy(['calendar' => $calendar, 'uid' => $uid]);
    }

    /**
     * The row a pulled change belongs to, matched on the remote's own id.
     *
     * Scoped to the calendar rather than looked up on remoteId alone: provider
     * ids are only unique within a calendar, and two calendars on one account
     * holding the same meeting is the normal case, not an edge one.
     */
    public function findOneByRemoteId(Calendar $calendar, string $remoteId): ?CalendarEvent
    {
        return $this->findOneBy(['calendar' => $calendar, 'remoteId' => $remoteId]);
    }

    /**
     * Everything on this calendar that owes the remote a write, oldest first.
     *
     * Ordered by id so the pushes go out in the order the edits were made. It
     * matters for the one sequence that is not commutative: an event created
     * and then deleted before either left produces a PendingCreate row that is
     * already gone, and any other ordering would push the delete for a resource
     * the remote has not been told about yet.
     *
     * @return list<CalendarEvent>
     */
    public function findPendingSync(Calendar $calendar): array
    {
        return $this->findBy(
            ['calendar' => $calendar, 'syncState' => SyncState::pendingCases()],
            ['id' => 'ASC'],
        );
    }

    /**
     * Locally known rows on this calendar that a full read did not mention.
     *
     * The other half of a resync: a full read returns every live event and no
     * tombstones, so anything deleted at the remote while the sync token was
     * dead is learned only by its absence. Rows with no remoteId are excluded —
     * an event made here and not yet pushed has never been at the remote, and
     * "the remote did not mention it" says nothing about it.
     *
     * QueryBuilder because NOT IN over a possibly-empty list is not expressible
     * through findBy(), and because the empty case has to be a different query
     * rather than `NOT IN ()`, which is a syntax error in SQL.
     *
     * @param list<string> $seenRemoteIds
     *
     * @return list<CalendarEvent>
     */
    public function findRemoteRowsNotIn(Calendar $calendar, array $seenRemoteIds): array
    {
        $query = $this->createQueryBuilder('event')
            ->where('event.calendar = :calendar')
            ->andWhere('event.remoteId IS NOT NULL')
            ->setParameter('calendar', $calendar);

        if ([] !== $seenRemoteIds) {
            $query->andWhere('event.remoteId NOT IN (:seen)')
                ->setParameter('seen', $seenRemoteIds);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * Rows on this calendar the remote never gave us.
     *
     * The exact complement of findRemoteRowsNotIn()'s population, and the one
     * question unsubscribing has to ask: everything with a remoteId is a copy
     * of something the provider still holds, so deleting it loses nothing,
     * while everything without one exists only here. That second set is what an
     * extracted booking looks like when the user has pointed
     * Account::SETTING_CALENDAR_TARGET at a mirrored calendar, and deleting it
     * with the subscription would destroy the only copy of a dinner
     * reservation because somebody unticked a calendar.
     *
     * @return list<CalendarEvent>
     */
    public function findRowsTheRemoteNeverGave(Calendar $calendar): array
    {
        return $this->createQueryBuilder('event')
            ->where('event.calendar = :calendar')
            ->andWhere('event.remoteId IS NULL')
            ->setParameter('calendar', $calendar)
            ->orderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recurring events whose materialised occurrences may not reach far enough
     * yet — everything unbounded, plus anything ending after the horizon the
     * last sweep wrote.
     *
     * QueryBuilder because that "plus" is an OR between a null test and a
     * comparison, and findBy() joins its criteria with AND.
     *
     * @return list<CalendarEvent>
     */
    public function findNeedingHorizonExtension(DateTimeImmutable $horizonEnd): array
    {
        return $this->createQueryBuilder('event')
            ->where('event.isRecurring = true')
            ->andWhere('event.recurrenceUntil IS NULL OR event.recurrenceUntil > :horizonEnd')
            ->setParameter('horizonEnd', $horizonEnd)
            ->orderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
