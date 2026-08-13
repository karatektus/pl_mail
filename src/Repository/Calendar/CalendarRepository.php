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
     * The calendar a push notification names, by the channel id it carries.
     *
     * A named method although it is one findOneBy, because of who calls it: two
     * unauthenticated endpoints reachable from the internet, whose entire
     * attribution of a notification to a user's calendar is this lookup. It is
     * unfiltered on purpose — no user, no active flag — since the channel id is
     * 128 bits minted here and the secret is checked immediately after. Adding
     * a filter would only change a refusal into a silent miss.
     *
     * At most one row by construction: uniq_calendar_push_channel_id.
     */
    public function findOneByPushChannel(string $channelId): ?Calendar
    {
        return $this->findOneBy(['pushChannelId' => $channelId]);
    }

    /**
     * Every mirrored calendar push could be registered for, oldest first.
     *
     * The same "mirrored and bound to a remote" definition findDueForSync()
     * uses, deliberately: a calendar the sweep syncs is exactly a calendar push
     * could deliver for, and two definitions of that would drift into a
     * calendar that polls but never registers, or worse the reverse.
     *
     * Unlimited, unlike findDueForSync(). That query dispatches a job per row
     * into a queue shared with mail; this one is walked in a console process
     * that then does nothing at all for a calendar whose channel is live — one
     * column read per row — so capping it would only mean an install's last
     * calendars never getting push.
     *
     * @return list<Calendar>
     */
    public function findMirrored(): array
    {
        return $this->createQueryBuilder('calendar')
            ->where('calendar.role = :remote')
            ->andWhere('calendar.remoteId IS NOT NULL')
            ->setParameter('remote', CalendarRole::Remote)
            ->orderBy('calendar.id', 'ASC')
            ->getQuery()
            ->getResult();
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
     * ── The backoff arm ──────────────────────────────────────────────────────
     * A calendar that cannot sync never updates lastSyncedAt, so on the two
     * criteria above it is due again on every single sweep, for as long as it
     * stays broken — which is how three calendars produced 2 193 identical
     * error lines in two days on the install this was written for. Calendars
     * inside a backoff window are excluded here, at the point the work is
     * created, rather than being dispatched and then declined: a job that is
     * queued only to do nothing still costs a worker, still carries a retry
     * policy, and still ends up in the failure transport.
     *
     * `syncBackoffUntil IS NULL` is an explicit arm for the same reason the
     * lastSyncedAt one is — a null compares as unknown, not as true, and
     * without it this would skip every healthy calendar and sweep only the
     * broken ones.
     *
     * @return list<Calendar>
     */
    public function findDueForSync(DateTimeImmutable $before, int $limit = 200, ?DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('calendar')
            ->addSelect('COALESCE(calendar.lastSyncedAt, :epoch) AS HIDDEN dueSince')
            ->where('calendar.role = :remote')
            ->andWhere('calendar.remoteId IS NOT NULL')
            ->andWhere('calendar.lastSyncedAt IS NULL OR calendar.lastSyncedAt < :before')
            ->andWhere('calendar.syncBackoffUntil IS NULL OR calendar.syncBackoffUntil <= :now')
            ->setParameter('remote', CalendarRole::Remote)
            ->setParameter('before', $before)
            ->setParameter('now', $now ?? new DateTimeImmutable())
            ->setParameter('epoch', new DateTimeImmutable('@0'))
            ->orderBy('dueSince', 'ASC')
            ->addOrderBy('calendar.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
