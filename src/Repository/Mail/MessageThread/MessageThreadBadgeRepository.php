<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The numbers beside the lists: unread and new threads per role, per label, per
 * account and per tab, and retiring the new marker once a list has been seen.
 *
 * Counted by the same conditions as the lists, which MessageThreadListCriteria holds.
 */
class MessageThreadBadgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * Mail held for the assistant is not in the Inbox yet, as far as the
     * sidebar is concerned (#371).
     *
     * A held conversation has no category (MessageThreader::createThread()),
     * and that is what keeps it out of every inbox tab until the verdict or
     * the timed release. The sidebar's Inbox row read the Inbox label alone,
     * so it lit its dot and raised its badge the moment the mail was stored,
     * and the tab beside it caught up seconds later, when the release
     * published the second change. Two halves of one arrival, on the screen
     * at different times. Now the row waits for the same release the tabs
     * do, and both are patched from the one counts answer that follows it.
     *
     * The test is on the thread's category alone, not on the messages'
     * categoryHeldAt: a NULL category is how a thread says it is in no tab,
     * which is the statement the badge has to agree with, and it needs no
     * join. Every other role keeps counting: holding is about the tabs, and
     * only the Inbox has them.
     *
     * The Inbox role only, and as an OR rather than a WHERE on the role,
     * because the callers group every role in one query.
     */
    private function excludeHeldFromInbox(QueryBuilder $qb): void
    {
        $qb->andWhere(
            $qb->expr()->orX(
                'l.role <> :heldRole',
                't.category IS NOT NULL',
            ),
        )->setParameter('heldRole', LabelRole::Inbox);
    }

    /**
     * Grouped COUNT per system role — an aggregate over a join, which is two
     * things Doctrine's API cannot do and one query instead of a role's worth.
     *
     * CONVERSATIONS with unread mail, not unread messages. It used to be
     * SUM(t.unreadCount), which answered a question the list beside it could
     * not: the badge said how many unread MESSAGES a role held, the list under
     * it draws one row per conversation, and a thread holding three unread
     * replies made those two numbers differ by two. Harmless while the badge
     * was only ever read, and not harmless once it became the thing you click
     * to see exactly that mail — a "4" that opens three rows is the Trash
     * "188 unread against a list of 193" all over again.
     *
     * countUnreadForStarred() had already been written this way, so this also
     * ends a disagreement between the badges themselves: Starred counted
     * conversations while every other badge counted messages.
     *
     * @return array<string,int> role value → conversations holding unread mail
     */
    public function countUnreadPerRole(UserInterface $user): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.role AS role', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NOT NULL')
            ->andWhere('t.unreadCount > 0')
            ->setParameter('user', $user)
            ->groupBy('l.role');

        // The badge has to agree with the list under it, so a trashed thread
        // stops counting towards Archive or Sent — but the Trash row is one of
        // the groups being summed here, and it must keep counting its own.
        // Hence the OR rather than a plain exclusion.
        $qb->andWhere(
            $qb->expr()->orX(
                'l.role = :trashRole',
                't.binned = false',
            ),
        )->setParameter('trashRole', LabelRole::Trash);

        $this->excludeHeldFromInbox($qb);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $role = $row['role'];

            if ($role instanceof LabelRole) {
                $role = $role->value;
            }

            $counts[(string) $role] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * Conversations holding unread mail in the Inbox, per account — what the
     * badge on each unified inbox in the sidebar adds up (#265).
     *
     * countUnreadPerRole()'s Inbox group, split by account rather than
     * summed, with the same exclusions, so the badges of all of a reader's
     * inboxes add up to the number the single Inbox badge showed. A
     * conversation belongs to one account, so adding per-account counts
     * cannot count one twice.
     *
     * @return array<int, int> account id => conversations holding unread mail
     */
    public function countUnreadInboxPerAccount(UserInterface $user): array
    {
        $qb = $this->inboxPerAccount($user, 'unreadCount')
            ->andWhere('t.unreadCount > 0')
            ->andWhere('t.binned = false');

        return $this->foldPerAccount($qb, 'unreadCount');
    }

    /**
     * New conversations in the Inbox, per account: the dot beside each
     * unified inbox. countNewPerRole()'s Inbox group, split the same way as
     * countUnreadInboxPerAccount() above and for the same reason.
     *
     * @return array<int, int> account id => new conversations
     */
    public function countNewInboxPerAccount(UserInterface $user, \DateTimeImmutable $now): array
    {
        $qb = $this->inboxPerAccount($user, 'newCount')
            ->andWhere('t.binned = false');

        $this->restrictToNew($qb, $now);

        return $this->foldPerAccount($qb, 'newCount');
    }

    /** The shared shape of the two per-account inbox counts above. */
    private function inboxPerAccount(UserInterface $user, string $alias): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->select('a.id AS accountId', 'COUNT(DISTINCT t.id) AS ' . $alias)
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->groupBy('a.id');

        $this->excludeHeldFromInbox($qb);

        return $qb;
    }

    /** @return array<int, int> */
    private function foldPerAccount(QueryBuilder $qb, string $field): array
    {
        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['accountId']] = (int) $row[$field];
        }

        return $counts;
    }

    /**
     * The same grouped COUNT for custom labels — conversations holding unread
     * mail, for the reason countUnreadPerRole() gives.
     *
     * @return array<int,int> label id → conversations holding unread mail
     */
    public function countUnreadPerUserLabel(UserInterface $user, ?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.id AS labelId', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NULL')
            ->andWhere('t.unreadCount > 0')
            ->setParameter('user', $user)
            ->groupBy('l.id');

        // Narrowed for the folder list under one account, where a count across
        // every account is an answer to a question nobody asked: the rows
        // beside it list that account's mail only.
        if (null !== $account) {
            $qb->andWhere('t.account = :account')
                ->setParameter('account', $account);
        }

        // Custom labels only (role IS NULL above), so there is no bin row to
        // spare here — a trashed thread simply stops counting.
        MessageThreadListCriteria::excludeTrashed($qb);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['labelId']] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * One number for everything filed under a custom label — what the LABELS
     * heading shows while the section is collapsed.
     *
     * NOT a sum of countUnreadPerUserLabel(), and that is the whole reason it
     * is a query of its own. Those counts are per label and a thread may carry
     * several, so adding them up reports one conversation two or three times:
     * the section would claim more unread than expanding it could show, which
     * is the specific way a rolled-up number loses people's trust.
     *
     * Stated as "threads carrying at least one custom label" via a subquery
     * instead, so each is counted once however many labels are on it.
     *
     * A COUNT of those threads rather than a SUM over them, matching the
     * per-label badges it stands in for: the heading has to be the number of
     * rows expanding the section would show you, and those rows are
     * conversations.
     */
    public function countUnreadInUserLabels(UserInterface $user): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.unreadCount > 0')
            ->andWhere(
                't.id IN ('
                    . 'SELECT labelled.id FROM ' . MessageThread::class . ' labelled'
                    . ' JOIN labelled.labels userLabel'
                    . ' WHERE userLabel.role IS NULL)',
            )
            ->setParameter('user', $user);

        // Same rule the per-label counts follow: a trashed thread stops
        // counting, so the heading cannot be louder than the rows under it.
        MessageThreadListCriteria::excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * COUNT DISTINCT over a join to Account — neither expressible through
     * count(), which takes field-to-value criteria on this entity alone.
     */
    public function countUnreadForStarred(UserInterface $user): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->andWhere('t.unreadCount > 0')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retire the marker on the threads that were just rendered.
     *
     * A bulk DQL UPDATE rather than a property write per entity, for the
     * reason it is called at all: this runs on every list render, and fifty
     * managed entities flushed one by one is fifty UPDATEs on the hot path.
     *
     * `listedAt IS NULL` in the WHERE is not redundant with the caller having
     * filtered already. Two tabs rendering the same page race here, and the
     * guard makes the loser a no-op instead of moving a timestamp that already
     * meant something.
     *
     * @param list<int> $threadIds ids from the rendered page, and only those —
     *        never the whole query, or page 1 would retire page 2's badges
     *
     * @return int rows actually retired
     */
    public function markListed(array $threadIds, \DateTimeImmutable $at): int
    {
        if ([] === $threadIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.listedAt', ':at')
            ->where('t.id IN (:ids)')
            ->andWhere('t.listedAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('ids', $threadIds)
            ->getQuery()
            ->execute();
    }

    /**
     * The same retirement, for ids that arrived from the browser.
     *
     * markListed() trusts its caller because its caller just read the threads
     * out of the database itself. This one is reachable by anybody who can POST
     * a JSON array, so ownership is a WHERE clause rather than an assumption:
     * without it, a list of guessed ids would let one account retire another
     * account's badges.
     *
     * A subselect on Account rather than a join, because DQL UPDATE cannot
     * join — and an id-by-id load-and-check would be one SELECT per row for a
     * request that fires on every list view.
     *
     * @param list<int> $threadIds
     *
     * @return int rows actually retired
     */
    public function markListedForUser(array $threadIds, UserInterface $user, \DateTimeImmutable $at): int
    {
        if ([] === $threadIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.listedAt', ':at')
            ->where('t.id IN (:ids)')
            ->andWhere('t.listedAt IS NULL')
            ->andWhere(
                't.account IN (SELECT owned.id FROM ' . Account::class . ' owned WHERE owned.usr = :user)',
            )
            ->setParameter('at', $at)
            ->setParameter('ids', $threadIds)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * The "is new" predicate, in one place.
     *
     * Never shown, still unread, AND arrived inside MessageThread::NEW_WINDOW.
     * Every count below calls this instead of writing `t.listedAt IS NULL`
     * itself, because
     * the counts feed dots and the dots have to agree with the badges the list
     * renders — and the list decides with MessageThread::isNewAt(). One rule,
     * two languages; this is the seam where they are kept identical.
     *
     * An aged-out thread keeps listedAt NULL rather than being stamped as
     * shown: the column says WHEN THE ROW WAS PUT IN FRONT OF THE USER, and
     * writing a timestamp there for mail nobody ever saw would be a false
     * statement recorded permanently — one the rethread backfill COALESCEs
     * forward, and one any later feature reading "was this displayed" would
     * inherit. The cost is a range predicate on last_message_at, which is
     * already the list's sort column.
     */
    private function restrictToNew(QueryBuilder $qb, \DateTimeImmutable $now): void
    {
        $qb->andWhere('t.listedAt IS NULL')
            // Read is seen. Not because listedAt is per client — it is
            // account-wide, and plMail's own surfaces already agree through it
            // — but because mail read in something that is NOT plMail arrives
            // already \Seen with no plMail surface having drawn its row. That
            // is how a dot could sit over a list with nothing bold in it.
            ->andWhere('t.unreadCount > 0')
            ->andWhere('t.lastMessageAt IS NOT NULL')
            ->andWhere('t.lastMessageAt >= :newSince')
            ->setParameter('newSince', MessageThread::newSince($now));
    }

    /**
     * New threads per inbox category — what puts the dot on a Gmail tab.
     *
     * The same shape as countByCategoryForUnifiedInbox() and for the same
     * reason: a GROUP BY with an aggregate, which is one query rather than one
     * per tab on every page load.
     *
     * @return array<string,int>
     * @param list<int>|null $accountIds one unified inbox's accounts, see findForUnifiedInbox()
     */
    public function countNewByCategoryForUnifiedInbox(UserInterface $user, \DateTimeImmutable $now, ?array $accountIds = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'IDENTITY(t.userCategory) AS userCategory', 'COUNT(DISTINCT t.id) AS newCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->groupBy('t.category')
            ->addGroupBy('t.userCategory')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->restrictToNew($qb, $now);
        MessageThreadListCriteria::excludeTrashed($qb);
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        return MessageThreadListCriteria::foldByTab($qb->getQuery()->getResult(), 'newCount', MessageThreadListCriteria::switchedOffBy($user));
    }

    /**
     * Who the new mail on each tab is from — the names behind the Gmail-style
     * sender hint under a category tab.
     *
     * One query for every category, like the counts above, but this one has to
     * look INSIDE the threads: the hint names the newest message's sender, and
     * the thread row does not carry one. So every message of every new thread
     * comes back flat — bounded, because "new" is capped at the 24-hour window
     * — and the newest-per-thread pick happens in PHP, where it is a
     * comparison rather than a correlated subquery per row.
     *
     * Newest thread first, one name per sender, capped: the hint is a teaser,
     * not a roster, and the count beside it already says how much the names
     * stand in for.
     *
     * @return array<string, list<string>> category value → sender display
     *         names, newest arrival first, at most $perCategory
     * @param list<int>|null $accountIds one unified inbox's accounts, see findForUnifiedInbox()
     */
    public function newSendersByCategoryForUnifiedInbox(
        UserInterface $user,
        \DateTimeImmutable $now,
        int $perCategory = 3,
        ?array $accountIds = null,
    ): array {
        $absorbed = MessageThreadListCriteria::switchedOffBy($user);

        $qb = $this->createQueryBuilder('t')
            ->select(
                't.id AS threadId',
                't.category AS category',
                'IDENTITY(t.userCategory) AS userCategory',
                't.lastMessageAt AS arrivedAt',
                'm.fromName AS fromName',
                'm.fromAddress AS fromAddress',
                // The same chain ThreadParticipants::newest() walks: createdAt
                // closes it, so every message has an instant to compare on.
                'COALESCE(m.receivedAt, m.sentAt, m.createdAt) AS messageAt',
            )
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->join(Message::class, 'm', 'WITH', 'm.thread = t')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->restrictToNew($qb, $now);
        MessageThreadListCriteria::excludeTrashed($qb);
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        // Newest message per thread. COALESCE hydrates as a "Y-m-d H:i:s"
        // string, which orders chronologically as a plain string compare.
        /** @var array<int, array{category: string, arrivedAt: \DateTimeImmutable|null, name: string, at: string}> $threads */
        $threads = [];

        foreach ($qb->getQuery()->getResult() as $row) {
            $id = (int) $row['threadId'];
            $at = (string) $row['messageAt'];

            if (true === isset($threads[$id]) && $threads[$id]['at'] >= $at) {
                continue;
            }

            $name = trim((string) ($row['fromName'] ?? ''));

            if ('' === $name) {
                $name = trim((string) ($row['fromAddress'] ?? ''));
            }

            $threads[$id] = [
                'category'  => MessageThreadListCriteria::tabKeyOf($row['category'], $row['userCategory'] ?? null, $absorbed),
                'arrivedAt' => $row['arrivedAt'],
                'name'      => $name,
                'at'        => $at,
            ];
        }

        $entries = array_values(array_filter(
            $threads,
            static fn (array $entry): bool => '' !== $entry['name'],
        ));

        usort(
            $entries,
            static fn (array $a, array $b): int => $b['arrivedAt'] <=> $a['arrivedAt'],
        );

        $senders = [];

        foreach ($entries as $entry) {
            $list = $senders[$entry['category']] ?? [];

            if (count($list) >= $perCategory || true === in_array($entry['name'], $list, true)) {
                continue;
            }

            $list[] = $entry['name'];

            $senders[$entry['category']] = $list;
        }

        return $senders;
    }

    /**
     * New threads per system role, for the sidebar dots.
     *
     * COUNT of threads, not SUM of unreadCount — the dot says "something
     * arrived here", and a conversation is one arrival however many messages
     * it holds.
     *
     * The Trash-or-not-trashed OR is lifted from countUnreadPerRole(), and is
     * load-bearing for the same reason: the bin has to keep counting its own
     * while every other row stops counting what was thrown away.
     *
     * @return array<string,int> role value → new thread count
     */
    public function countNewPerRole(UserInterface $user, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.role AS role', 'COUNT(DISTINCT t.id) AS newCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('l.role');

        $this->restrictToNew($qb, $now);

        $qb->andWhere(
            $qb->expr()->orX(
                'l.role = :trashRole',
                't.binned = false',
            ),
        )->setParameter('trashRole', LabelRole::Trash);

        $this->excludeHeldFromInbox($qb);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $role = $row['role'];

            if ($role instanceof LabelRole) {
                $role = $role->value;
            }

            $counts[(string) $role] = (int) $row['newCount'];
        }

        return $counts;
    }

    /**
     * The same count for custom labels.
     *
     * @return array<int,int> label id → new thread count
     */
    public function countNewPerUserLabel(UserInterface $user, \DateTimeImmutable $now, ?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.id AS labelId', 'COUNT(DISTINCT t.id) AS newCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NULL')
            ->setParameter('user', $user)
            ->groupBy('l.id');

        $this->restrictToNew($qb, $now);
        MessageThreadListCriteria::narrowToAccount($qb, $account);
        MessageThreadListCriteria::excludeTrashed($qb);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['labelId']] = (int) $row['newCount'];
        }

        return $counts;
    }

    /** New threads among the starred ones. */
    public function countNewForStarred(UserInterface $user, \DateTimeImmutable $now): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user);

        $this->restrictToNew($qb, $now);
        MessageThreadListCriteria::excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
