<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\BookingPage;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingPage>
 */
class BookingPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingPage::class);
    }

    /**
     * The owner's pages, newest first, with the destination calendar attached.
     *
     * The destination is fetch-joined and the busy set is not: the list names
     * where bookings land on every row, and counts the busy calendars rather
     * than naming them — a count is one collection load the ORM does lazily
     * when it is asked, and only for the rows on screen.
     *
     * @return list<BookingPage>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('page')
            ->addSelect('calendar')
            ->leftJoin('page.calendar', 'calendar')
            ->where('page.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('page.createdAt', 'DESC')
            ->addOrderBy('page.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One page by the digest of its token.
     *
     * Answers a disabled page too, for the same reason the share link
     * repository answers a revoked one: whether that is a 404 is the reader's
     * decision, and it wants both to look identical from outside.
     */
    public function findOneByDigest(string $digest): ?BookingPage
    {
        return $this->findOneBy(['tokenDigest' => $digest]);
    }

    /** One of this user's pages by id, with the ownership check inside the query. */
    public function findOneForUser(User $user, int $id): ?BookingPage
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }
}
