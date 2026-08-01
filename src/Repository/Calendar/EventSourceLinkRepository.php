<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use DateTimeImmutable;
use App\Entity\Mail\Message;
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
     * What this message put on the calendar — the "Added to …" chip in the
     * thread view, and the answer to "why is this on my calendar?".
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
