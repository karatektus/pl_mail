<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\CalendarBooking;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarBooking>
 */
class CalendarBookingRepository extends ServiceEntityRepository
{
    /**
     * How a taken instant is spelled as an array key.
     *
     * A contract between this and BookingAvailabilityReader, named once rather
     * than repeated at both ends. Seconds and no zone, because the column is a
     * plain UTC timestamp — a format carrying an offset would key the same
     * moment two ways the day either side started reading it in a local zone.
     */
    public const string INSTANT_KEY = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarBooking::class);
    }

    /**
     * The instants already taken on one page, within a window.
     *
     * ONE query for the whole window, not one per slot. That is the difference
     * between a booking page that renders in a few milliseconds and one that
     * makes a hundred and twenty round trips to answer "what is free next
     * month", and it is why this returns a set to be looked up in PHP rather
     * than a predicate to be asked per slot.
     *
     * Keyed by the UTC instant in a canonical format so the caller can test
     * membership with array_key_exists rather than comparing DateTimeImmutable
     * objects — which compare by value including microseconds and zone, and
     * would answer false for two spellings of the same moment.
     *
     * Scalar result rather than entities: nothing here needs the booker's name,
     * and hydrating a month of bookings to read one column off each is work
     * whose only visible effect is the memory it uses.
     *
     * @return array<string, true>
     */
    public function takenInstantsFor(
        BookingPage       $page,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $rows = $this->createQueryBuilder('booking')
            ->select('booking.startsAt')
            ->where('booking.page = :page')
            ->andWhere('booking.startsAt >= :from')
            ->andWhere('booking.startsAt < :to')
            ->setParameter('page', $page)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getScalarResult();

        $taken = [];

        foreach ($rows as $row) {
            $startsAt = $row['startsAt'];

            if (true === $startsAt instanceof DateTimeImmutable) {
                $taken[$startsAt->format(self::INSTANT_KEY)] = true;
            }
        }

        return $taken;
    }

    /**
     * The owner's bookings from now forward, newest slot first.
     *
     * Fetch-joins the page because the list names which page each booking came
     * through, and a booking's whole meaning is "somebody took an hour of the
     * intro-call page".
     *
     * @return list<CalendarBooking>
     */
    public function findUpcomingForUser(User $user, DateTimeImmutable $from, int $limit = 50): array
    {
        return $this->createQueryBuilder('booking')
            ->addSelect('page')
            ->join('booking.page', 'page')
            ->where('booking.usr = :usr')
            ->andWhere('booking.startsAt >= :from')
            ->setParameter('usr', $user)
            ->setParameter('from', $from)
            ->orderBy('booking.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
