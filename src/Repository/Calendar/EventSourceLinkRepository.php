<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\EventSourceLink;
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
