<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

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
