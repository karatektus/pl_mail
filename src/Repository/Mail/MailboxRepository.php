<?php

namespace App\Repository\Mail;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Mailbox>
 */
class MailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailbox::class);
    }

    /** Doctrine's own findBy(), keyed in PHP — the index is not a query. */
    public function findIndexedByFullPath(Account $account): array
    {
        $mailboxes = $this->findBy(['account' => $account]);
        $indexed = [];

        foreach ($mailboxes as $mailbox) {
            $indexed[$mailbox->fullPath] = $mailbox;
        }

        return $indexed;
    }

    public function findSentMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::SENT]);
    }

    /**
     * The folders somebody can choose between: the ones the server still
     * lists.
     *
     * A folder the server has stopped listing is left out. Nothing polls it
     * (see ImapAccountSyncer) and it is on its way to being removed, so a
     * switch beside it would be wired to nothing.
     *
     * By path, which is a stable order and not the one they are shown in:
     * FolderTree arranges them as the tree their paths describe.
     *
     * @return list<Mailbox>
     */
    public function findForAccountOrdered(Account $account): array
    {
        return $this->findBy(['account' => $account, 'missingSince' => null], ['fullPath' => 'ASC']);
    }

    /**
     * The oldest full-listing this account's folders have between them, or null
     * if any of them has never had one.
     *
     * This is the coverage guarantee behind remote deletion. A row that went
     * missing from one folder may simply be in another, so the only moment its
     * absence means anything is once *every* folder has been listed since — and
     * the folder that was listed longest ago is what that comes down to.
     *
     * Null is the important answer and is why this returns one rather than
     * skipping the folders it cannot speak for. A single never-swept folder
     * means the account has no such moment yet, and nothing may be erased on
     * the strength of the folders that have been looked in.
     *
     * Sync-disabled folders are excluded because nothing ever lists them, so
     * including them would make the answer permanently null. The cost is stated
     * where it lands: a message moved by hand into a folder the user has turned
     * sync off for is, to plMail, a message that left the account.
     */
    public function earliestSweepAcross(Account $account): ?\DateTimeImmutable
    {
        /** @var list<array{sweptAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('mailbox')
            ->select('mailbox.sweptAt')
            ->where('mailbox.account = :account')
            ->andWhere('mailbox.isSyncEnabled = :enabled')
            ->setParameter('account', $account)
            ->setParameter('enabled', true)
            ->getQuery()
            ->getArrayResult();

        if (0 === count($rows)) {
            return null;
        }

        $earliest = null;

        foreach ($rows as $row) {
            $sweptAt = $row['sweptAt'];

            if (null === $sweptAt) {
                return null;
            }

            if (null === $earliest || $sweptAt < $earliest) {
                $earliest = $sweptAt;
            }
        }

        return $earliest;
    }

    /**
     * Mailboxes an IDLE supervisor should hold a connection for.
     *
     * QueryBuilder on two counts: whether the owning account is still active is
     * a field of Account, which findBy() cannot reach, and the account is
     * fetch-joined because the supervisor opens a connection with its
     * credentials for every row — leaving it lazy is an N+1 across the whole
     * install at boot.
     */
    public function findIdleEnabledAndSyncEnabled(): array
    {
        $queryBuilder = $this->createQueryBuilder('mailbox');

        $queryBuilder
            ->innerJoin('mailbox.account', 'account')
            ->addSelect('account')
            ->andWhere('mailbox.isIdleEnabled = :isIdleEnabled')
            ->andWhere('mailbox.isSyncEnabled = :isSyncEnabled')
            ->andWhere('account.isActive = :isActive')
            ->setParameter('isIdleEnabled', true)
            ->setParameter('isSyncEnabled', true)
            ->setParameter('isActive', true);

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Make a folder's deletion sweep due right now, without lying about what
     * has been listed.
     *
     * The idle supervisor calls this when the server announces an EXPUNGE:
     * the next sync should sweep immediately rather than on the cadence. The
     * clock only ever moves BACKWARD — LEAST() — because advancing it would
     * claim a listing that never happened, and the reaper's coverage rule
     * trusts these timestamps. NULL stays NULL for the same reason: "never
     * listed" is a fact, and a fact this method does not get to invent.
     */
    public function markSweepDue(int $mailboxId): void
    {
        $backdated = new \DateTimeImmutable(
            '-' . \App\Service\Imap\VanishedMessageReconciler::SWEEP_INTERVAL_MINUTES . ' minutes',
        );

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE mailbox SET swept_at = LEAST(swept_at, :backdated) WHERE id = :id AND swept_at IS NOT NULL',
            ['backdated' => $backdated->format('Y-m-d H:i:s'), 'id' => $mailboxId],
        );
    }

    /**
     * How many of an account's folders still have history to bring in.
     *
     * What "the first import is still running" means for IMAP, which has no
     * account-level cursor to ask. See findImporting() for what counts.
     *
     * A count rather than a boolean so the caller can tell "none outstanding"
     * from "no folders at all"; see InitialImportState.
     */
    public function countAwaitingFirstSync(Account $account): int
    {
        return count($this->findImporting($account));
    }

    /**
     * How much history the folders of each of these accounts set out to bring
     * in, how much of it is still to come, and how many folders are not done.
     *
     * One grouped query for all of a person's accounts, because the topbar
     * asks on every page and the answer is nearly always "none are". The
     * conditions are findImporting()'s, except that finished folders are
     * counted too: what they brought in is part of the total.
     *
     * A folder nobody has planned yet has no total and counts as importing,
     * with nothing to add to the figures until its first page has asked the
     * server what is there.
     *
     * @param list<Account> $accounts
     *
     * @return array<int, array{total: int, remaining: int, importing: int}> by account id
     */
    public function importTotalsByAccount(array $accounts): array
    {
        if ([] === $accounts) {
            return [];
        }

        $rows = $this->createQueryBuilder('mailbox')
            ->select('IDENTITY(mailbox.account) AS accountId')
            ->addSelect('COALESCE(SUM(mailbox.importTotal), 0) AS total')
            ->addSelect('COALESCE(SUM(mailbox.importRemaining), 0) AS remaining')
            ->addSelect('SUM(CASE WHEN mailbox.importFloorUid IS NULL OR mailbox.importFloorUid <> 0 THEN 1 ELSE 0 END) AS importing')
            ->andWhere('mailbox.account IN (:accounts)')
            ->andWhere('mailbox.isSyncEnabled = :enabled')
            ->andWhere('mailbox.isSelectable = :selectable')
            ->andWhere('mailbox.missingSince IS NULL')
            ->setParameter('accounts', $accounts)
            ->setParameter('enabled', true)
            ->setParameter('selectable', true)
            ->groupBy('mailbox.account')
            ->getQuery()
            ->getArrayResult();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row['accountId']] = [
                'total'     => (int) $row['total'],
                'remaining' => (int) $row['remaining'],
                'importing' => (int) $row['importing'],
            ];
        }

        return $totals;
    }

    /**
     * An account's folders that still have history to bring in, the ones to
     * read first leading.
     *
     * "Still importing" is the folder's own marker — see
     * Mailbox::$importFloorUid — and not the absence of a syncedAt, which was
     * the test while a first sync read a folder whole: a folder gets its
     * syncedAt at the end of its first pass, and that pass now ends at once,
     * with new mail in hand and the history still to come.
     *
     * Only folders that are synced at all, that the server still lists and
     * that it will open: nothing fetches the others, so nothing would ever
     * finish them.
     *
     * THE ORDER IS THE PRIORITY. The Inbox first, because it is what a person
     * opens; then what they wrote; then their own folders; the archive after
     * those, since on Gmail over IMAP "All Mail" is every message again; and
     * Spam and the bin last, which nobody is waiting for. PHP sorts it rather
     * than SQL: the rank is a rule about an enum, and it belongs beside the
     * enum's other rules rather than in a CASE expression here.
     *
     * @return list<Mailbox>
     */
    public function findImporting(Account $account): array
    {
        /** @var list<Mailbox> $mailboxes */
        $mailboxes = $this->createQueryBuilder('mailbox')
            ->andWhere('mailbox.account = :account')
            ->andWhere('mailbox.isSyncEnabled = :enabled')
            ->andWhere('mailbox.isSelectable = :selectable')
            ->andWhere('mailbox.missingSince IS NULL')
            ->andWhere('mailbox.importFloorUid IS NULL OR mailbox.importFloorUid <> 0')
            ->setParameter('account', $account)
            ->setParameter('enabled', true)
            ->setParameter('selectable', true)
            ->orderBy('mailbox.fullPath', 'ASC')
            ->getQuery()
            ->getResult();

        // Stable, so the path order survives within a rank.
        usort(
            $mailboxes,
            static fn (Mailbox $a, Mailbox $b): int => MailboxSpecialUse::importRank($a->specialUse) <=> MailboxSpecialUse::importRank($b->specialUse),
        );

        return $mailboxes;
    }
}
