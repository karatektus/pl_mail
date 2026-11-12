<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The conditions a thread list and the badges counting it share: which threads
 * an account or a set of accounts holds, what is in the bin, and which tab a
 * thread belongs to.
 *
 * One place, because a badge that counts by a different rule than the list it
 * sits beside shows a number the list does not have.
 */
final class MessageThreadListCriteria
{
    /**
     * The built-in categories this reader switched off, as stored values.
     *
     * @return list<string>
     */
    public static function switchedOffBy(UserInterface $user): array
    {
        if (false === $user instanceof \App\Entity\User\User) {
            return [];
        }

        return array_map(
            static fn (MessageCategory $category): string => $category->value,
            $user->categorySorting->switchedOff(),
        );
    }

    /**
     * Which tab a grouped row belongs to: the reader's own when the thread is
     * filed in one, else the built-in category.
     *
     * One definition, because the three grouped counts and the sender hint must
     * key a tab identically or the strip's numbers and its dots describe
     * different tabs. The key is InboxTab's, which for a built-in is the value
     * it always was.
     */
    /**
     * @param list<string> $absorbed switched-off built-ins, counted as Primary
     */
    public static function tabKeyOf(mixed $category, mixed $userCategoryId, array $absorbed = []): string
    {
        if (null !== $userCategoryId) {
            return InboxTab::CUSTOM_PREFIX . (int) $userCategoryId;
        }

        $value = $category instanceof MessageCategory ? $category->value : (string) $category;

        return true === in_array($value, $absorbed, true) ? MessageCategory::Primary->value : $value;
    }

    /**
     * Fold grouped rows into tab => count.
     *
     * Added up rather than assigned: the query groups by the built-in category
     * AND the reader's tab, so one of the reader's tabs arrives as several rows
     * — one per built-in category its threads happen to have — and keeping the
     * last of them would report a fraction of the tab.
     *
     * @param iterable<array<string,mixed>> $rows
     * @param list<string>                  $absorbed switched-off built-ins, counted as Primary
     *
     * @return array<string,int>
     */
    public static function foldByTab(iterable $rows, string $countField, array $absorbed = []): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $key = self::tabKeyOf($row['category'], $row['userCategory'] ?? null, $absorbed);

            $counts[$key] = ($counts[$key] ?? 0) + (int) $row[$countField];
        }

        return $counts;
    }

    /** No-op when no account was asked for, so callers need no branch. */
    /**
     * Narrow an inbox query to the accounts of one unified inbox (#265).
     *
     * Null is no narrowing — every account of the reader's, as the single
     * unified inbox always was. An empty list is an inbox with no accounts in
     * it, which lists nothing: `IN ()` is not SQL, so it is said as a
     * condition that is never true rather than left out, which would have
     * shown an empty inbox everything.
     *
     * @param list<int>|null $accountIds
     */
    public static function narrowToAccounts(QueryBuilder $qb, ?array $accountIds): void
    {
        if (null === $accountIds) {
            return;
        }

        if ([] === $accountIds) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere('a.id IN (:inboxAccounts)')
            ->setParameter('inboxAccounts', $accountIds, ArrayParameterType::INTEGER);
    }

    public static function narrowToAccount(QueryBuilder $qb, ?Account $account): void
    {
        if (null === $account) {
            return;
        }

        $qb->andWhere('t.account = :account')->setParameter('account', $account);
    }

    /**
     * "…and it is not in the bin."
     *
     * Gmail's rule, and the one users expect: a trashed conversation shows in
     * Trash and nowhere else. plMail could not express that before, because
     * every list here filters on thread_label and a thread's labels are the
     * union of its messages' labels (ThreadLabelSynchronizer). Trashing keeps
     * whatever custom labels a message already carried — ThreadStatusUpdater
     * removes Inbox and nothing else — so a thread labelled "Receipts" and
     * then deleted still matched `findForLabel(Receipts)` and sat in the list
     * looking live. Opening it from there is how someone edits a conversation
     * they believe they threw away a month ago.
     *
     * "In the bin" means EVERY message of it is (#268). The rule used to be
     * "carries the Trash label", and the thread's labels are the union of its
     * messages' — so one deleted message took the whole conversation out of
     * the Inbox, its tab and every label list, and with it whatever arrived
     * in it afterwards. Replies arrive in such threads all the time: through
     * References/In-Reply-To onto a parent that was deleted, and through the
     * subject fallback onto a conversation binned weeks ago. That mail was in
     * the Inbox, unread, shown by the phone app and nowhere on the web.
     * A conversation deleted as a whole has every message in the bin and
     * still leaves its Receipts list, which is what the rule above is for.
     *
     * Read off MessageThread::$binned, which the database keeps (#223). It
     * used to be worked out here from the messages, a sub-query that took
     * about 170 ms on every list and badge of a 67k-thread mailbox.
     *
     * Deliberately not applied to the Trash view itself, nor to the per-account
     * Trash folder row — see the callers, each of which decides. Applying it
     * everywhere would empty the one list that is supposed to be full.
     */
    public static function excludeTrashed(QueryBuilder $qb): void
    {
        $qb->andWhere('t.binned = false');
    }
}
