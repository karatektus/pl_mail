<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use DateTimeImmutable;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Calendar\Extraction\IcsEventExtractor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventSourceLink>
 */
class EventSourceLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventSourceLink::class);
    }

    /**
     * When the newest applied extraction for this event arrived.
     *
     * The tiebreak when two claims carry the same SEQUENCE, which is every
     * claim from a source that has no revision number — a booking
     * confirmation has only a date. Reads the MESSAGE's date rather than the
     * link's own createdAt: mail is not processed in the order it was sent,
     * and a backfill processes all of it at once.
     *
     * MAX over a COALESCE across a joined entity — an aggregate, an expression
     * and a join, none of which Doctrine's API expresses. The alternative is
     * loading every link and its message to compute one timestamp.
     */
    public function latestAppliedAt(CalendarEvent $event): ?DateTimeImmutable
    {
        $result = $this->createQueryBuilder('link')
            ->select('MAX(COALESCE(message.receivedAt, message.sentAt))')
            ->join('link.message', 'message')
            ->where('link.event = :event')
            ->andWhere('link.applied = true')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $result ? null : new DateTimeImmutable((string) $result);
    }

    /**
     * The distinct claims that produced this event.
     *
     * A projection rather than the links themselves, and a query rather than
     * CalendarEvent::$sourceLinks. The collection is the inverse side, so it is
     * empty — not lazy, empty — for an event created in the same unit of work
     * that is reading it: nothing populates the inverse side of an association
     * whose owning rows were persisted separately. Dismissing such an event
     * silently suppressed nothing at all.
     *
     * Not filtered on `applied`. A superseded claim is precisely the one a
     * re-run would apply next.
     *
     * @return list<string>
     */
    public function findDedupKeysForEvent(CalendarEvent $event): array
    {
        // An event that has never been flushed cannot be bound as a parameter,
        // and has no committed links to find either. Both facts say the same
        // thing — see EventReconciler::supersedes(), which meets this from the
        // other side.
        if (null === $event->id) {
            return [];
        }

        $keys = $this->createQueryBuilder('link')
            ->select('DISTINCT link.dedupKey')
            ->where('link.event = :event')
            ->andWhere("link.dedupKey <> ''")
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(strval(...), $keys));
    }

    /**
     * The invitations a whole conversation carries, in one query.
     *
     * Asked per thread rather than per message because the invite card is
     * rendered from a partial that is included once per message: a lookup on
     * the message alone would be an indexed query per row, on every thread
     * anybody opens, to answer "no" for almost all of them. One query for the
     * conversation is the same information for a constant cost.
     *
     * Not filtered on `applied`. A link goes to applied = false when a later
     * message supersedes it, and the message it belongs to is still the
     * message someone opens to answer the invitation — hiding the card there
     * would mean the original invite is the one place an RSVP is impossible.
     *
     * @return list<EventSourceLink>
     */
    public function findInvitesForThread(MessageThread $thread): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('message', 'event', 'calendar')
            ->join('link.message', 'message')
            ->join('link.event', 'event')
            ->join('event.calendar', 'calendar')
            ->where('message.thread = :thread')
            ->andWhere('link.extractor = :extractor')
            ->setParameter('thread', $thread)
            ->setParameter('extractor', IcsEventExtractor::NAME)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same answer for a message that belongs to no conversation — a
     * standalone invite that threading never joined to anything.
     *
     * @return list<EventSourceLink>
     */
    public function findInvitesForMessage(Message $message): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('event', 'calendar')
            ->join('link.event', 'event')
            ->join('event.calendar', 'calendar')
            ->where('link.message = :message')
            ->andWhere('link.extractor = :extractor')
            ->setParameter('message', $message)
            ->setParameter('extractor', IcsEventExtractor::NAME)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The message each of these events currently reflects, keyed by event id —
     * the "why is this on my calendar?" answer, for a whole page of rows.
     *
     * The mirror of findAppliedForMessage() and deliberately the other way
     * round: that one starts from a message somebody has open, this one starts
     * from a list of events nobody has opened anything for. "Happening Soon"
     * draws a dozen rows at once, so asking per row — through this repository or
     * through CalendarEvent::$sourceLinks, which is lazy and would be no
     * cheaper — is a dozen indexed queries to render one panel.
     *
     * The NEWEST applied claim wins, not the first. A booking is described by
     * several messages, and the one that answers "why is this on my calendar?"
     * is the one the event currently reflects: the reschedule, not the original
     * confirmation it replaced. Ordered by the MESSAGE's date rather than the
     * link's own createdAt for the reason latestAppliedAt() gives — mail is not
     * processed in the order it was sent, and a backfill processes all of it at
     * once — and the ascending sort with a last-write-wins fold is what makes
     * "newest" mean the same thing here as it does there.
     *
     * COALESCE as a HIDDEN select rather than straight in the ORDER BY: DQL
     * will not sort on an expression that is not in the select list, and HIDDEN
     * keeps the result a list of entities rather than a list of arrays.
     *
     * @param list<CalendarEvent> $events
     *
     * @return array<int, Message>
     */
    public function findLatestAppliedMessageByEvent(array $events): array
    {
        if (0 === count($events)) {
            return [];
        }

        $links = $this->createQueryBuilder('link')
            ->addSelect('message', 'COALESCE(message.receivedAt, message.sentAt) AS HIDDEN appliedAt')
            ->join('link.message', 'message')
            ->where('link.event IN (:events)')
            ->andWhere('link.applied = true')
            ->setParameter('events', $events)
            ->orderBy('appliedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byEvent = [];

        foreach ($links as $link) {
            $eventId = $link->event?->id;

            if (null === $eventId || null === $link->message) {
                continue;
            }

            $byEvent[$eventId] = $link->message;
        }

        return $byEvent;
    }

    /**
     * What this message put on the calendar — the "Added to …" chip in the
     * thread view, and the answer to "why is this on my calendar?".
     *
     * QueryBuilder for the fetch-join: the chip names the event and the
     * calendar it landed on, so leaving either lazy is two extra queries per
     * chip on a thread that may carry several.
     *
     * @return list<EventSourceLink>
     */
    public function findAppliedForMessage(Message $message): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('event', 'calendar')
            ->join('link.event', 'event')
            ->join('event.calendar', 'calendar')
            ->where('link.message = :message')
            ->andWhere('link.applied = true')
            ->setParameter('message', $message)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
