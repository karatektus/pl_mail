<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageLocation;
use App\Entity\Mail\Pop3Uidl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Messages as an IMAP server holds them: UIDs, flags, the folders a copy lives in,
 * duplicate copies, and rows that vanished from the server.
 */
class MessageImapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * The UIDs above $aboveUid this mailbox has already stored.
     *
     * QueryBuilder for the projection: the syncer diffs a set of integers
     * against what the server offers, and hydrating a Message per UID to read
     * one column would make a routine poll cost a mailbox load.
     *
     * Bounded below because the syncer only ever asks the server for UIDs
     * above its high-water mark: loading every UID a large folder has ever
     * stored, on every poll, to check the handful above it was the cost.
     *
     * Read off the locations alone: the UIDs are theirs, and the unique index
     * on (mailbox_id, imap_uid) answers this without touching message.
     */
    public function findSyncedUids(Mailbox $mailbox, int $aboveUid = 0): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('l.imapUid')
            ->from(MessageLocation::class, 'l')
            ->where('l.mailbox = :mailbox')
            ->andWhere('l.imapUid IS NOT NULL')
            ->andWhere('l.imapUid > :aboveUid')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('aboveUid', $aboveUid)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * A sibling's own IMAP copies that a Gmail mailbox has merged into, a page
     * at a time in id order: the rows with a folder of their own AND a carrier.
     *
     * The folder is what tells these apart from the other carried rows. A row
     * Gmail built for a sibling has no IMAP folder and Gmail is its only
     * source; a row with one is the IMAP server's, and Gmail only knows about
     * it. See GmailifyLinks.
     *
     * By the carrier's id rather than the entity: the callers page through
     * tens of thousands of rows and clear the entity manager between pages,
     * which would leave an entity argument detached.
     *
     * @param int|null $carrierId null for every carrier on the install
     * @return list<Message>
     */
    public function findImapCopiesCarried(?int $carrierId, int $afterId, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.gmailCarrierAccount IS NOT NULL')
            ->andWhere('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l WHERE l.message = m)')
            ->andWhere('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit);

        if (null !== $carrierId) {
            $qb->andWhere('IDENTITY(m.gmailCarrierAccount) = :carrierId')->setParameter('carrierId', $carrierId);
        }

        return $qb->getQuery()->getResult();
    }

    public function countImapCopiesCarried(?int $carrierId): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.gmailCarrierAccount IS NOT NULL')
            ->andWhere('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l WHERE l.message = m)');

        if (null !== $carrierId) {
            $qb->andWhere('IDENTITY(m.gmailCarrierAccount) = :carrierId')->setParameter('carrierId', $carrierId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * What this mailbox's rows currently say about their flags, and nothing
     * else about them.
     *
     * The flag pass compares a folder listing against these five values and
     * changes, in the ordinary case, none of them. It used to reach for the
     * whole entity to find that out — `SELECT m.* FROM message WHERE
     * mailbox_id = ? AND imap_uid IS NOT NULL`, hydrated — which means every
     * body, every stored HTML part, the headers and the tsvector, for a
     * comparison that touches a boolean, a boolean and a small JSON array. In
     * the slow-query panel of a real install that one statement was the
     * largest single cost in the database: 262 seconds over 1,187 calls,
     * 698,517 rows, because it runs per folder per sweep for ever.
     *
     * So the comparison reads this, and only the rows that turn out to differ
     * are hydrated (see ImapFlagReconciler). Array hydration on purpose: the
     * caller wants values to compare, and an entity it does not intend to
     * change is an entity the UnitOfWork has to track and check anyway.
     *
     * With each, its copy here: the location and the flags the server last
     * reported for that copy, which is what a listing is compared against
     * first (#415, see MessageLocation::$serverFlags).
     *
     * @return array<int,array{id:int,flags:list<string>,seen:bool,flagged:bool,pending:bool,location:int,serverFlags:list<string>|null}>
     *         imapUid => its current flag state
     */
    public function findFlagStateByUid(Mailbox $mailbox): array
    {
        /** @var list<array{id:int,imapUid:int,flags:list<string>|null,seenAt:?\DateTimeInterface,starredAt:?\DateTimeInterface,flagsTouchedAt:?\DateTimeInterface,location:int,serverFlags:list<string>|null}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'l.imapUid', 'm.flags', 'm.seenAt', 'm.starredAt', 'm.flagsTouchedAt', 'l.id AS location', 'l.serverFlags')
            ->join('m.locations', 'l')
            ->where('l.mailbox = :mailbox')
            ->andWhere('l.imapUid IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getArrayResult();

        $state = [];

        foreach ($rows as $row) {
            $state[(int) $row['imapUid']] = [
                'id'      => (int) $row['id'],
                'flags'   => $row['flags'] ?? [],
                'seen'    => null !== $row['seenAt'],
                'flagged' => null !== $row['starredAt'],
                'pending'     => null !== $row['flagsTouchedAt'],
                'location'    => (int) $row['location'],
                'serverFlags' => $row['serverFlags'],
            ];
        }

        return $state;
    }

    /**
     * One message in one folder, by the UID the server calls it.
     *
     * `(mailbox_id, imap_uid)` is unique by constraint, so this is a point
     * lookup rather than a search — which is what makes it a fit answer to a
     * flag notification that already named exactly one message. The only reply
     * the IDLE loop used to have to one of those was to list the whole folder.
     */
    public function findOneInMailboxByUid(Mailbox $mailbox, int $uid): ?Message
    {
        return $this->createQueryBuilder('m')
            ->join('m.locations', 'l')
            ->where('l.mailbox = :mailbox')
            ->andWhere('l.imapUid = :uid')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('uid', $uid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The rows a flag pass has decided it actually needs to change.
     *
     * @param list<int> $ids
     *
     * @return array<int,Message> imapUid => Message
     */
    public function findByIdsKeyedByUid(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Message> $rows */
        $rows = $this->createQueryBuilder('m')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $located = [];

        foreach ($rows as $row) {
            $located[(int) $row->imapUid] = $row;
        }

        return $located;
    }

    /**
     * Stored mail of this account that a new copy might be (#415): the rows
     * with one of these Message-IDs or server ids. ImapCopies decides
     * which, if any, is the same email; this only narrows the account down to
     * the handful worth comparing.
     *
     * By message.account, never across accounts — see ImapCopies.
     *
     * @param list<string> $messageIds normalised, without angle brackets
     * @param list<string> $serverIds  X-GM-MSGID / EMAILID values
     *
     * @return list<Message>
     */
    public function findCopyCandidates(Account $account, array $messageIds, array $serverIds): array
    {
        if ([] === $messageIds && [] === $serverIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->where('m.account = :account')
            ->setParameter('account', $account)
            ->orderBy('m.id', 'ASC');

        $either = $qb->expr()->orX();

        if ([] !== $messageIds) {
            $either->add('m.messageId IN (:messageIds)');
            $qb->setParameter('messageIds', $messageIds);
        }

        if ([] !== $serverIds) {
            $either->add('m.serverEmailId IN (:serverIds)');
            $qb->setParameter('serverIds', $serverIds);
        }

        /** @var list<Message> */
        return $qb->andWhere($either)->getQuery()->getResult();
    }

    /**
     * Which of these messages the server keeps in more than one place, so that
     * losing one copy is not losing the email (#415).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function findIdsWithSeveralLocations(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(intval(...), $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.message)')
            ->from(MessageLocation::class, 'l')
            ->where('l.message IN (:ids)')
            ->groupBy('l.message')
            ->having('COUNT(l.id) > 1')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Every message with a copy in this folder.
     *
     * @return list<Message>
     */
    public function findKeptIn(Mailbox $mailbox): array
    {
        /** @var list<Message> */
        return $this->createQueryBuilder('m')
            ->where('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l WHERE l.message = m AND l.mailbox = :mailbox)')
            ->setParameter('mailbox', $mailbox)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Forget this folder's copies of mail that is primarily somewhere else.
     *
     * For a mirror folder that has been switched off: nothing lists it any
     * more, so its copies would go on claiming an address nobody checks. By
     * query, because only secondary copies go — a message's primary is left
     * for MailboxSyncer::dropMirrorCopies(), which erases it as it always did —
     * so no message's $mailbox / $imapUid changes underneath it.
     */
    public function dropSecondaryLocationsIn(Mailbox $mailbox): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(MessageLocation::class, 'l')
            ->where('l.mailbox = :mailbox')
            ->andWhere('EXISTS (SELECT m.id FROM ' . Message::class . ' m WHERE m = l.message AND m.mailbox <> :mailbox)')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->execute();
    }

    /**
     * Every addressable server copy of these messages, for a job that has to
     * reach each one: [messageId, mailboxId, UID].
     *
     * @param list<int> $ids
     *
     * @return list<array{int,int,int}>
     */
    public function findCopiesOf(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.message) AS message', 'IDENTITY(l.mailbox) AS mailbox', 'l.imapUid')
            ->from(MessageLocation::class, 'l')
            ->where('l.message IN (:ids)')
            ->andWhere('l.imapUid IS NOT NULL')
            ->orderBy('l.id', 'ASC')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [(int) $row['message'], (int) $row['mailbox'], (int) $row['imapUid']],
            $rows,
        );
    }

    /**
     * Emails this folder keeps that are stored as more than one message, each
     * as the ids of its messages, the one to keep first — see ImapCopyMerger.
     *
     * One account, one Message-ID, one size, no two server ids that differ,
     * and every row kept somewhere by the server. Grouped in SQL: the accounts
     * that need this have it for most of their mail.
     *
     * @return list<list<int>>
     */
    public function findDuplicateCopyGroups(Mailbox $mailbox, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT array_to_json(array_agg(m.id ORDER BY (m.gmail_id IS NULL), m.id))'
            . ' FROM message m'
            . ' WHERE m.account_id = :account'
            . ' AND m.message_id IS NOT NULL AND m.rfc822_size IS NOT NULL'
            . ' AND EXISTS (SELECT 1 FROM message_location l WHERE l.message_id = m.id)'
            . ' AND (m.message_id, m.rfc822_size) IN ('
            . '   SELECT k.message_id, k.rfc822_size FROM message k JOIN message_location kl ON kl.message_id = k.id'
            . '   WHERE kl.mailbox_id = :mailbox AND k.rfc822_size IS NOT NULL AND k.message_id IS NOT NULL'
            . ' )'
            . ' GROUP BY m.message_id, m.rfc822_size'
            . ' HAVING COUNT(*) > 1 AND COUNT(DISTINCT m.server_email_id) <= 1'
            . ' LIMIT :limit',
            ['account' => $mailbox->account->id, 'mailbox' => $mailbox->id, 'limit' => $limit],
        );

        return array_map(
            static fn (string $ids): array => array_map(intval(...), json_decode($ids, true, flags: JSON_THROW_ON_ERROR)),
            $rows,
        );
    }

    /**
     * Give rows stored before #415 the size their copy in this folder has.
     *
     * The size is what guards a Message-ID match (ImapCopies), and a row
     * without one is never recognised: a copy of it that turns up in another
     * folder is stored again, and a move another client made is only found by
     * asking the server about each Message-ID. Each folder's listing fills the
     * rows it keeps, once; after that this finds nothing to do.
     *
     * @param array<int, int> $sizesByUid UID => RFC822.SIZE, as the folder listed them
     *
     * @return int rows given a size
     */
    public function backfillSizes(Mailbox $mailbox, array $sizesByUid): int
    {
        if ([] === $sizesByUid) {
            return 0;
        }

        $connection = $this->getEntityManager()->getConnection();

        $unsized = array_flip(array_map(intval(...), $connection->fetchFirstColumn(
            'SELECT l.imap_uid FROM message_location l JOIN message m ON m.id = l.message_id'
            . ' WHERE l.mailbox_id = ? AND l.imap_uid IS NOT NULL AND m.rfc822_size IS NULL',
            [$mailbox->id],
        )));

        $wanted = array_intersect_key($sizesByUid, $unsized);
        $filled = 0;

        foreach (array_chunk($wanted, 500, true) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $uid => $size) {
                $values[] = '(?::int, ?::int)';
                $params[] = $uid;
                $params[] = $size;
            }

            // The folder's id last, as its placeholder comes after the VALUES.
            $params[] = (int) $mailbox->id;

            $filled += (int) $connection->executeStatement(
                'UPDATE message m SET rfc822_size = v.size'
                . ' FROM (VALUES ' . implode(', ', $values) . ') AS v(uid, size), message_location l'
                . ' WHERE l.mailbox_id = ? AND l.imap_uid = v.uid AND l.message_id = m.id AND m.rfc822_size IS NULL',
                $params,
            );
        }

        return $filled;
    }

    /**
     * Write down what the server just reported for these copies' flags.
     *
     * In SQL and in chunks, because the first listing after #415 records
     * every copy in the folder, and a folder can hold a hundred thousand.
     * After that only the copies that changed come here.
     *
     * @param array<int, list<string>> $flagsByLocation location id => canonical flags
     */
    public function recordServerFlags(array $flagsByLocation): void
    {
        $connection = $this->getEntityManager()->getConnection();

        foreach (array_chunk($flagsByLocation, 500, true) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $locationId => $flags) {
                $values[] = '(?::int, ?::json)';
                $params[] = $locationId;
                $params[] = json_encode(array_values($flags), JSON_THROW_ON_ERROR);
            }

            $connection->executeStatement(
                'UPDATE message_location l SET server_flags = v.flags FROM (VALUES ' . implode(', ', $values) . ') AS v(id, flags) WHERE l.id = v.id',
                $params,
            );
        }
    }

    /**
     * Every address this mailbox holds, as row id => UID.
     *
     * findSyncedUids() answers "have I seen this UID", which is all the
     * incremental path needs. The sweep asks the opposite question — which of
     * my rows is the server no longer offering — and to answer it has to name
     * the rows, not merely count them.
     *
     * @return array<int,int> messageId => imapUid
     */
    public function findLocatedUidsById(Mailbox $mailbox): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.message) AS id', 'l.imapUid')
            ->from(MessageLocation::class, 'l')
            ->where('l.mailbox = :mailbox')
            ->andWhere('l.imapUid IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getArrayResult();

        $located = [];

        foreach ($rows as $row) {
            $located[(int) $row['id']] = (int) $row['imapUid'];
        }

        return $located;
    }

    /**
     * Record that these rows were not in their folder's listing.
     *
     * A bulk update rather than hydrated entities: a sweep of a large folder
     * after a bad night can name thousands of rows, and loading a Message per
     * row to write one nullable column would turn the safety net into the
     * slowest thing in the poll.
     *
     * `vanishedAt IS NULL` in the predicate keeps the *first* absence as the
     * instant that counts. Refreshing it on every sweep would push the deadline
     * out forever and nothing would ever be reaped.
     *
     * @param list<int> $ids
     */
    public function markVanished(array $ids, \DateTimeImmutable $at): int
    {
        return $this->updateVanishedAt($ids, $at, onlyUnmarked: true);
    }

    /**
     * Take the mark off rows the server has just produced after all.
     *
     * @param list<int> $ids
     */
    public function clearVanished(array $ids): int
    {
        return $this->updateVanishedAt($ids, null, onlyUnmarked: false);
    }

    /**
     * @param list<int> $ids
     */
    private function updateVanishedAt(array $ids, ?\DateTimeImmutable $at, bool $onlyUnmarked): int
    {
        if (0 === count($ids)) {
            return 0;
        }

        $affected = 0;

        // Chunked because Postgres has a bind-parameter ceiling and a folder
        // that was rebuilt server-side can put every row it has in this list.
        foreach (array_chunk($ids, 1000) as $chunk) {
            $qb = $this->getEntityManager()->createQueryBuilder()
                ->update(Message::class, 'm')
                ->set('m.vanishedAt', ':at')
                ->where('m.id IN (:ids)')
                ->setParameter('at', $at)
                ->setParameter('ids', $chunk);

            if (true === $onlyUnmarked) {
                $qb->andWhere('m.vanishedAt IS NULL');
            } else {
                $qb->andWhere('m.vanishedAt IS NOT NULL');
            }

            $affected += (int) $qb->getQuery()->execute();
        }

        return $affected;
    }

    /**
     * Strip every stored UID in this folder, keeping the rows.
     *
     * What a UIDVALIDITY change means and the most that may be done about it.
     * The addresses are void; the mail is not, and the server may still have
     * all of it. An unlocated row is the shape SentCopyReconciler::claim()
     * re-matches by Message-ID when the folder is re-read, so this is a
     * re-match and not a wipe.
     *
     * The vanish marks go with them: a row with no address cannot be confirmed
     * gone by anything, so leaving it marked would only give the reaper
     * questions it can never answer.
     */
    public function unlocateAll(Mailbox $mailbox): int
    {
        // The locations first, in the same terms: an update by query goes past
        // Message::mirrorLocation(), so it has to say the same thing twice
        // until the message columns are gone (#415).
        $this->getEntityManager()->createQueryBuilder()
            ->update(MessageLocation::class, 'l')
            ->set('l.imapUid', ':nothing')
            ->where('l.mailbox = :mailbox')
            ->andWhere('l.imapUid IS NOT NULL')
            ->setParameter('nothing', null)
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->execute();

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(Message::class, 'm')
            ->set('m.imapUid', ':nothing')
            ->set('m.vanishedAt', ':nothing')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
            ->setParameter('nothing', null)
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->execute();
    }

    /**
     * Rows that went missing before every folder had been looked in.
     *
     * The cutoff is the caller's business and is the whole safety of this: it
     * passes the *earliest* sweep across the account's folders, so a row only
     * comes back from here once every one of them has been listed since it
     * vanished and none produced it. See VanishedMessageReconciler::reap().
     *
     * Ordered by the instant they vanished so the oldest evidence is acted on
     * first, and capped, because erasing is the one thing here that cannot be
     * undone by the next poll.
     *
     * @return list<Message>
     */
    public function findReapable(Account $account, \DateTimeImmutable $vanishedBefore, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.account = :account')
            ->andWhere('m.vanishedAt IS NOT NULL')
            ->andWhere('m.vanishedAt < :before')
            ->setParameter('account', $account)
            ->setParameter('before', $vanishedBefore)
            ->orderBy('m.vanishedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rows that are not mail: the epoch-dated corpses of a failed fetch.
     *
     * An IMAP FETCH that came back empty used to be assembled into a Message
     * anyway — see MessageSyncer::isUsableFetch(), which now refuses it. The
     * rows already written by that path are what this finds: no Message-ID, no
     * sender, no subject, no body, no attachments, and a receivedAt of the
     * epoch, because webklex's empty Attribute parses through Carbon as
     * `false` and lands on 1970-01-01.
     *
     * Every condition is required, and that is the point. The predicate has to
     * be unable to match mail a user would miss, and the way to guarantee that
     * is to demand the whole profile rather than any part of it:
     *
     *  - A genuinely subjectless message still carries a Message-ID and a
     *    sender, so it fails on both counts before the subject is even looked
     *    at.
     *  - A message whose Date header was missing is now stored at ingest time,
     *    never at the epoch, so it cannot drift into range.
     *  - An empty draft has no receivedAt at all rather than an epoch one,
     *    which is why the date test demands a real value BELOW the cutoff
     *    instead of accepting NULL. Drafts are excluded by label as well —
     *    belt and braces, because a draft is the one thing in the schema that
     *    is legitimately blank.
     *
     * The cutoff is a year wide rather than an exact equality so a row written
     * through a different timezone still matches; nothing real is dated 1970.
     *
     * @return list<Message>
     */
    public function findEpochGhosts(int $limit): array
    {
        $qb = $this->createQueryBuilder('m');

        return $qb
            ->where('m.receivedAt IS NOT NULL')
            ->andWhere('m.receivedAt < :epochCutoff')
            ->andWhere($qb->expr()->orX('m.messageId IS NULL', "m.messageId = ''"))
            ->andWhere($qb->expr()->orX('m.fromAddress IS NULL', "m.fromAddress = ''"))
            ->andWhere($qb->expr()->orX('m.subject IS NULL', "m.subject = ''"))
            ->andWhere($qb->expr()->orX('m.bodyText IS NULL', "m.bodyText = ''"))
            ->andWhere($qb->expr()->orX('m.bodyHtml IS NULL', "m.bodyHtml = ''"))
            ->andWhere($qb->expr()->orX('m.hasAttachments IS NULL', 'm.hasAttachments = false'))
            ->andWhere(
                $qb->expr()->notIn(
                    'm.id',
                    'SELECT drafted.id FROM ' . Message::class . ' drafted'
                        . ' JOIN drafted.labels draftedLabel'
                        . ' WHERE draftedLabel.role = :draftsRole',
                ),
            )
            ->setParameter('epochCutoff', new \DateTimeImmutable('1971-01-01'))
            ->setParameter('draftsRole', LabelRole::Drafts)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * A message this account already holds under the given RFC Message-ID that
     * has no IMAP location yet — claimable by the syncer when the server-side
     * copy shows up.
     *
     * Two kinds of row qualify, and they are the same situation twice: a
     * Gmail-imported copy waiting for its IMAP twin, and a message this
     * installation composed and sent itself, whose Sent-folder copy is about to
     * come back. Both already went through the whole ingest pipeline and both
     * are what JMAP clients, attachments and calendar links point at, so the
     * server copy has to attach to them rather than land as a second row.
     *
     * Deliberately not restricted by gmailId any more. It used to be, and that
     * is precisely why a web-composed reply came back from Sent as a duplicate:
     * the row had no gmailId, so nothing recognised it.
     *
     * An unsent draft cannot be caught by this. Drafts never get a Message-ID —
     * MessageSendService mints one at send — so they have nothing to match on.
     *
     * QueryBuilder for the ownership test: it spans two associations, since a
     * row reaches its account through its mailbox or through its thread
     * depending on where it came from.
     *
     * $forSentFolder false leaves out the rows waiting for their Sent copy —
     * the send path files those in the Sent mailbox, or in none when the
     * account has no Sent folder, and they carry no gmailId. Only the Sent
     * folder may claim them. A mail you Cc'd to yourself arrives in INBOX
     * under the same Message-ID, often before the Sent copy, and used to take
     * the sent row over: the message left Sent and the Sent copy that followed
     * was inserted as a second row. Gmail-imported copies (gmailId set) and
     * rows a UIDVALIDITY rebuild unlocated in their own folder stay claimable
     * from anywhere.
     */
    public function findUnlocatedByMessageId(Account $account, string $messageId, bool $forSentFolder = true): ?Message
    {
        $qb = $this->createQueryBuilder('message')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('message.imapUid IS NULL')
            ->andWhere('mailbox.account = :account OR thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults(1);

        if (false === $forSentFolder) {
            $qb->andWhere(
                'message.gmailId IS NOT NULL'
                . ' OR (mailbox.id IS NOT NULL AND (mailbox.specialUse IS NULL OR mailbox.specialUse != :sent))',
            )->setParameter('sent', MailboxSpecialUse::SENT);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * A row this account holds with no server address that is the same
     * message as one a server is offering, judged by Message-ID or — for mail
     * that has none — by MessageSyncer::fingerprintOf(). Null when there is
     * none to adopt.
     *
     * For an account that changed incoming protocol (#289), whose rows all
     * lost their addresses in the switch. Two narrowings keep it from taking
     * one message for another:
     *
     *  - Only unlocated rows. A row with an IMAP UID is somewhere on a server
     *    already, and is SentCopyReconciler's to match.
     *  - $notInPop3Ledger: only rows no UIDL names yet. A POP3 adoption does
     *    not give the row an address, so without this two copies on the server
     *    that look alike would both be taken for the one row, and the second
     *    — which the delete mode would then remove — would exist nowhere.
     *
     * A fingerprint match also leaves out unsent drafts (no sentAt), which
     * have no Message-ID either and are nobody's copy of anything.
     *
     * @param array{from: string, subject: string, date: \DateTimeImmutable}|null $fingerprint
     */
    public function findAdoptable(Account $account, string $messageId, ?array $fingerprint, bool $notInPop3Ledger): ?Message
    {
        $qb = $this->createQueryBuilder('message')
            ->where('message.account = :account')
            ->andWhere('message.imapUid IS NULL')
            ->setParameter('account', $account)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults(1);

        if ('' !== $messageId) {
            $qb->andWhere('message.messageId = :messageId')->setParameter('messageId', $messageId);
        } elseif (null !== $fingerprint) {
            $qb->andWhere("message.messageId IS NULL OR message.messageId = ''")
                ->andWhere('message.subject = :subject')
                ->andWhere('message.receivedAt = :date')
                ->andWhere('message.sentAt IS NOT NULL')
                ->setParameter('subject', $fingerprint['subject'])
                ->setParameter('date', $fingerprint['date']);

            if ('' === $fingerprint['from']) {
                $qb->andWhere("message.fromAddress IS NULL OR message.fromAddress = ''");
            } else {
                $qb->andWhere('message.fromAddress = :from')->setParameter('from', $fingerprint['from']);
            }
        } else {
            return null;
        }

        if (true === $notInPop3Ledger) {
            $qb->andWhere('NOT EXISTS (SELECT ledger.id FROM ' . Pop3Uidl::class . ' ledger WHERE ledger.message = message)');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * A *server-side* copy already stored in this exact folder under this RFC
     * Message-ID.
     *
     * The guard against a second server-side copy of one sent message: plenty
     * of providers file their own Sent copy of everything they relay, so our
     * APPEND and their auto-save both sit in Sent with the same Message-ID and
     * different UIDs. One message, one row.
     *
     * The UID is what makes it server-side, and requiring it is not optional.
     * A message we sent is filed into the Sent *folder* by the send path itself
     * while it is still waiting for its server copy — so without this it
     * matches itself, is reported as already present, and never gets the UID it
     * came here to be given.
     *
     * Folder-scoped on purpose. Across folders the same Message-ID is normal —
     * a mail you sent to yourself is genuinely in both Sent and INBOX, and this
     * app models those as separate rows carrying separate labels.
     */
    public function findInMailboxByMessageId(Mailbox $mailbox, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->where('message.mailbox = :mailbox')
            ->andWhere('message.imapUid IS NOT NULL')
            ->andWhere('message.messageId = :messageId')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('messageId', $messageId)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Rows in a mirror folder (Mailbox::$mirrorsOtherFolders) whose email the
     * server also keeps in a real folder — the second copies #414 is about.
     *
     * "Keeps" means a row with an address the server has confirmed: a folder,
     * a UID, and no vanish mark. A row waiting for its copy after a move has
     * none of those yet, and erasing the mirror copy beside it would leave the
     * email with no confirmed address at all. Nor does a copy in another
     * mirror count: a message in both Starred and All Mail and nowhere else is
     * in neither for good, and erasing by this rule would take it from both.
     *
     * By Message-ID, the only key two folder copies share. Rows without one
     * are never matched, so they stay.
     *
     * @return list<Message>
     */
    public function findMirrorCopiesKeptElsewhere(Mailbox $mirror, int $limit): array
    {
        /** @var list<Message> */
        return $this->createQueryBuilder('message')
            ->where('message.mailbox = :mirror')
            ->andWhere('message.messageId IS NOT NULL')
            ->andWhere(
                'EXISTS (SELECT kept.id FROM ' . Message::class . ' kept'
                . ' JOIN kept.mailbox keptIn'
                . ' WHERE kept.account = message.account'
                . ' AND kept.messageId = message.messageId'
                . ' AND kept.mailbox <> message.mailbox'
                . ' AND kept.imapUid IS NOT NULL'
                . ' AND kept.vanishedAt IS NULL'
                . ' AND keptIn.mirrorsOtherFolders = false)',
            )
            ->setParameter('mirror', $mirror)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rows in a Sent folder that were written by the send path before it minted
     * Message-IDs: sent (so not a draft), filed into this folder, but carrying
     * neither an IMAP UID nor an RFC Message-ID.
     *
     * That combination has exactly one producer — MessageSendService as it was
     * before the identity fix — so this is not content matching, it is naming
     * the one shape of row the old bug left behind. Anything synced from a
     * server has a UID; anything composed since has a Message-ID; a draft has
     * no sentAt.
     *
     * @return list<Message>
     */
    public function findIdentitylessSentRows(Mailbox $mailbox): array
    {
        return $this->createQueryBuilder('message')
            ->where('message.mailbox = :mailbox')
            ->andWhere('message.imapUid IS NULL')
            ->andWhere('message.messageId IS NULL')
            ->andWhere('message.sentAt IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->orderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Server-side copies in the same folder that can only be the imported twin
     * of $ghost: same conversation, same sender, same subject, and actually
     * synced from the server.
     *
     * Thread rather than a time window is the anchor, and that is the point.
     * The two rows always share a thread — the imported copy was threaded onto
     * it by References, off the very headers the ghost's own send wrote — and a
     * thread is an identity the database holds, not a guess. A timestamp
     * comparison would have had to reconcile a locally-clocked sentAt with a
     * Date: header parsed out of the appended MIME, which is the sort of match
     * that works until it silently does not.
     *
     * Ordered so a caller pairing several of these consumes them predictably.
     *
     * @return list<Message>
     */
    public function findImportedTwinsOf(Message $ghost): array
    {
        $thread = $ghost->thread;

        if (null === $thread || null === $ghost->mailbox) {
            return [];
        }

        return $this->createQueryBuilder('message')
            ->where('message.thread = :thread')
            ->andWhere('message.mailbox = :mailbox')
            ->andWhere('message.imapUid IS NOT NULL')
            ->andWhere('LOWER(message.fromAddress) = LOWER(:fromAddress)')
            ->andWhere('message.subject = :subject')
            ->setParameter('thread', $thread)
            ->setParameter('mailbox', $ghost->mailbox)
            ->setParameter('fromAddress', (string) $ghost->fromAddress)
            ->setParameter('subject', (string) $ghost->subject)
            ->orderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Server-side copies of this message that the account holds in some *other*
     * folder.
     *
     * The candidate set for "did this message move here, or is it merely also
     * here?". Both readings are legitimate on plain IMAP — a mail addressed to
     * yourself really is in Sent and INBOX at once, and a COPY leaves the
     * original where it was — so this only gathers the candidates. Deciding
     * between them needs the server, and that decision lives in
     * SentCopyReconciler::claim().
     *
     * Ordered oldest first so the row that has been carrying the user's flags
     * and JMAP id the longest is the one offered up for relocation.
     *
     * @return list<Message>
     */
    public function findLocatedByMessageIdElsewhere(
        Account $account,
        string  $messageId,
        Mailbox $excluding,
    ): array {
        return $this->createQueryBuilder('message')
            ->where('message.account = :account')
            ->andWhere('message.messageId = :messageId')
            ->andWhere('message.imapUid IS NOT NULL')
            ->andWhere('message.mailbox IS NOT NULL')
            ->andWhere('message.mailbox != :excluding')
            ->setParameter('account', $account)
            ->setParameter('messageId', $messageId)
            ->setParameter('excluding', $excluding)
            ->orderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every server-side copy this account holds of one message, in any folder.
     *
     * @return list<Message>
     */
    public function findLocatedByMessageId(Account $account, string $messageId): array
    {
        return $this->createQueryBuilder('message')
            ->where('message.account = :account')
            ->andWhere('message.messageId = :messageId')
            ->andWhere('message.imapUid IS NOT NULL')
            ->andWhere('message.mailbox IS NOT NULL')
            ->orderBy('message.id', 'ASC')
            ->setParameter('account', $account)
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Message-IDs this mailbox holds that the account also holds somewhere else.
     *
     * The work list for the self-repair pass: every one of these is either a
     * message that was moved and left a ghost behind, or a copy that genuinely
     * exists twice. This cannot tell them apart and does not try — it narrows
     * millions of rows down to the handful worth asking the server about.
     *
     * Grouped in SQL rather than by loading both sides, because on the accounts
     * that need repairing most the duplicates number in the thousands. Limited
     * for the same reason: a sync must not turn into an unbounded probing run,
     * so the backlog drains a slice per pass.
     *
     * Rows the server confirmed as genuine copies since $recheckAfter are left
     * out: probing them again would find them all present again, and without
     * this they filled the slice every sync and starved the real ghosts behind
     * them. See Message::$copiesConfirmedAt.
     *
     * @return list<string>
     */
    public function findMessageIdsAlsoFiledElsewhere(Mailbox $mailbox, int $limit, \DateTimeImmutable $recheckAfter): array
    {

        $rows = $this->createQueryBuilder('message')
            ->select('message.messageId')
            ->innerJoin(
                Message::class,
                'twin',
                'WITH',
                'twin.messageId = message.messageId AND twin.account = message.account AND twin.mailbox != message.mailbox',
            )
            ->where('message.mailbox = :mailbox')
            ->andWhere('message.messageId IS NOT NULL')
            ->andWhere("message.messageId != ''")
            ->andWhere('message.imapUid IS NOT NULL')
            ->andWhere('twin.imapUid IS NOT NULL')
            ->andWhere('twin.mailbox IS NOT NULL')
            ->andWhere('message.copiesConfirmedAt IS NULL OR message.copiesConfirmedAt < :recheckAfter')
            ->groupBy('message.messageId')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('recheckAfter', $recheckAfter)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn ($id): string => (string) $id, $rows));
    }

    /**
     * Whether any row carries the epoch date a failed fetch leaves behind: one
     * cheap probe before findEpochGhosts().
     *
     * One probe of a partial index that is empty on any install that has
     * already been cleaned. See Version20260811160000, and note the literal: a
     * bound parameter cannot be proven to imply the index's condition, so the
     * index would be built and then not used.
     */
    public function hasEpochGhostSuspects(): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM message WHERE received_at < TIMESTAMP '1971-01-01' LIMIT 1",
        );
    }
}
