<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Calendar>
 */
class CalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Calendar::class);
    }

    /**
     * Every calendar a user owns, in sidebar order.
     *
     * @return list<Calendar>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The ones a view should actually read. Kept separate from findForUser()
     * because the settings page wants the hidden ones too.
     *
     * @return list<Calendar>
     */
    public function findVisibleForUser(UserInterface $user): array
    {
        return $this->findBy(
            ['usr' => $user, 'isVisible' => true],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );
    }

    public function findDefaultForUser(UserInterface $user): ?Calendar
    {
        return $this->findOneBy(['usr' => $user, 'isDefault' => true]);
    }

    /** The calendar mail from this account lands on. */
    public function findForAccount(Account $account): ?Calendar
    {
        return $this->findOneBy(['account' => $account, 'role' => CalendarRole::Account]);
    }

    /**
     * The calendars mirrored from one mail account's provider.
     *
     * Filtered on the role rather than on the account alone, and that is the
     * whole reason this is not findBy(['account' => …]) at the call site: every
     * account also owns a CalendarRole::Account calendar, which mirrors nothing
     * and is where extraction files what it finds in that account's mail.
     * Including it would offer the user the chance to "unsubscribe" from the
     * one calendar the provisioner would immediately make again.
     *
     * @return list<Calendar>
     */
    public function findMirroredForAccount(Account $account): array
    {
        return $this->findBy(
            ['account' => $account, 'role' => CalendarRole::Remote],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * The calendars mirrored from one connection.
     *
     * No role filter is needed here — an Integration has no calendar other than
     * the ones subscribing created — but it is asked for anyway, so the two
     * halves of the subscribe screen cannot answer "what do we already mirror?"
     * with two different rules.
     *
     * @return list<Calendar>
     */
    public function findMirroredForIntegration(Integration $integration): array
    {
        return $this->findBy(
            ['integration' => $integration, 'role' => CalendarRole::Remote],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );
    }

    public function findOneForUser(UserInterface $user, int $id): ?Calendar
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    /**
     * Mirrored calendars that have not been synced since $before, longest wait
     * first.
     *
     * QueryBuilder rather than findBy() for one reason: a calendar that has
     * never synced has a null lastSyncedAt, and `lastSyncedAt < :before` is
     * unknown rather than true for a null in SQL — so the naive criterion
     * skips exactly the calendars that most need the sweep, the ones just
     * subscribed to. The IS NULL arm is the whole point of the method.
     *
     * The ordering goes through a COALESCE rather than the column, because
     * Postgres sorts nulls last on an ascending order and the never-synced
     * calendars are the ones that most need to go first — a fresh subscription
     * would otherwise queue behind every established calendar and, at the
     * limit below, behind more of them than the sweep dispatches.
     *
     * Limited so one install with two hundred calendars does not dispatch two
     * hundred jobs into a queue that also carries mail. The remainder is picked
     * up by the next sweep, which is fifteen minutes away.
     *
     * @return list<Calendar>
     */
    public function findDueForSync(DateTimeImmutable $before, int $limit = 200): array
    {
        return $this->createQueryBuilder('calendar')
            ->addSelect('COALESCE(calendar.lastSyncedAt, :epoch) AS HIDDEN dueSince')
            ->where('calendar.role = :remote')
            ->andWhere('calendar.remoteId IS NOT NULL')
            ->andWhere('calendar.lastSyncedAt IS NULL OR calendar.lastSyncedAt < :before')
            ->setParameter('remote', CalendarRole::Remote)
            ->setParameter('before', $before)
            ->setParameter('epoch', new DateTimeImmutable('@0'))
            ->orderBy('dueSince', 'ASC')
            ->addOrderBy('calendar.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
