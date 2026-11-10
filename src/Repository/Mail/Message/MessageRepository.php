<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageLocation;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine's repository for Message: lookups by id, a thread's messages for
 * reading, list rows and previews, drafts and per-folder counts.
 *
 * Everything with a topic of its own sits beside it in this folder, one class
 * per topic: sending, filters, JMAP, labels, backfills, the assistant, IMAP
 * copies, provider ids, threading and arrival times. Not final, like its
 * siblings, because tests double them.
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findByIds(array $ids): array
    {
        if (0 === count($ids)) {
            return [];
        }

        return $this->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    /**
     * The same set, ordered as the mail server delivered it.
     *
     * The tiebreak on id is what makes the order total: two messages that
     * arrived in the same second have to thread in a stable sequence, or a
     * rebuild is not reproducible.
     *
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    public function findByIdsInArrivalOrder(array $ids): array
    {
        if (0 === count($ids)) {
            return [];
        }

        return $this->findBy(['id' => $ids], ['receivedAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Ids of every message hanging off these threads, as scalars.
     *
     * Scalars on purpose. The callers are about to delete the threads, and
     * hydrating the messages only to delete them leaves the unit of work in a
     * state where the reseed's own flush insists the threads it has just
     * persisted were never persisted ("A new entity was found through the
     * relationship Message#thread"). Nothing here wants the objects.
     *
     * @param list<int> $threadIds
     *
     * @return list<int>
     */
    public function findIdsForThreads(array $threadIds): array
    {
        if (0 === count($threadIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.thread IN (:threads)')
            ->setParameter('threads', $threadIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Which thread a message currently belongs to, without loading either.
     *
     * The rethread backfill asks this between clearing the EntityManager and
     * writing carried-over state back, so hydrating a Message here would pull
     * an entity into a unit of work that is deliberately empty.
     */
    public function findThreadIdFor(int $messageId): ?int
    {
        $threadId = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT thread_id FROM message WHERE id = :messageId',
            ['messageId' => $messageId],
        );

        if (false === $threadId || null === $threadId) {
            return null;
        }

        return (int) $threadId;
    }

    /**
     * How many messages are stored for an account.
     *
     * For the progress line of a Gmail import, which has no folders to add
     * up: what is stored against what the provider said the mailbox holds.
     * Asked only while such an import is running.
     */
    /**
     * Messages this Gmail mailbox brought in that live on another account:
     * mail addressed to a connected sibling, stored there, and copies the
     * sibling already had that took on their Gmail identity. Both point back
     * through Message::$gmailCarrierAccount.
     */
    public function countCarriedBy(Account $account): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.gmailCarrierAccount = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Does the thread contain a message sent by any of these addresses?
     *
     * Threading passes the candidate's sender *and* its recipients: a reply from
     * someone new to the conversation shares no sender with it, but its To/Cc
     * almost always names someone who has already posted. Checking senders only
     * would reject those replies.
     *
     * QueryBuilder because the comparison is on LOWER(fromAddress) — addresses
     * are stored as they arrived and matched case-insensitively — and because
     * this only ever needs to know whether a row exists, which is why it
     * selects a literal and stops at one instead of hydrating a Message.
     *
     * @param list<string> $addresses
     */
    public function existsWithAnyFromAddressInThread(array $addresses, MessageThread $thread): bool
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn(string $address): string => mb_strtolower(trim($address)), $addresses),
            static fn(string $address): bool => '' !== $address,
        )));

        if (0 === count($normalized)) {
            return false;
        }

        $result = $this->createQueryBuilder('m')
            ->select('1')
            ->where('m.thread = :thread')
            ->andWhere('LOWER(m.fromAddress) IN (:addresses)')
            ->setParameter('thread', $thread)
            ->setParameter('addresses', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }

    /**
     * Does the thread hold stored mail that is neither in Spam nor in the bin?
     *
     * Asked by MessageThreader::recordArrival() before a set-aside message may
     * move a conversation: one that lives in the Inbox must not be brought to
     * the top by the spam a filter has just taken out of it.
     *
     * Stored rows only, which is what the question needs: the arriving message
     * is not in the thread in the database yet, and is left out by id in case
     * it is. A literal and one row, for the reason existsWithAnyFromAddressInThread()
     * gives.
     */
    public function existsNotSetAsideInThread(MessageThread $thread, Message $except): bool
    {
        if (null === $thread->id) {
            return false;
        }

        $qb = $this->createQueryBuilder('m');

        $qb->select('1')
            ->where('m.thread = :thread')
            ->andWhere($qb->expr()->notIn(
                'm.id',
                'SELECT asideMessage.id FROM ' . Message::class . ' asideMessage'
                    . ' JOIN asideMessage.labels asideLabel'
                    . ' WHERE asideMessage.thread = :thread AND asideLabel.role IN (:asideRoles)',
            ))
            ->setParameter('thread', $thread)
            ->setParameter('asideRoles', [LabelRole::Spam, LabelRole::Trash])
            ->setMaxResults(1);

        if (null !== $except->id) {
            $qb->andWhere('m.id <> :except')
                ->setParameter('except', $except->id);
        }

        return null !== $qb->getQuery()->getOneOrNullResult();
    }

    /** A null criterion is Doctrine's IS NULL, so this needs no query of its own. */
    /**
     * The folder's own numbers, as the server would give them: the messages it
     * keeps, each once however many copies it holds (#415).
     */
    public function countUnseenForMailbox(Mailbox $mailbox): int
    {
        return $this->countKeptIn($mailbox, true);
    }

    public function countTotalForMailbox(Mailbox $mailbox): int
    {
        return $this->countKeptIn($mailbox, false);
    }

    private function countKeptIn(Mailbox $mailbox, bool $unseenOnly): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l WHERE l.message = m AND l.mailbox = :mailbox)')
            ->setParameter('mailbox', $mailbox);

        if (true === $unseenOnly) {
            $qb->andWhere('m.seenAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Label-based: covers Gmail drafts too (no mailbox to join through), and
     * the label is a to-many association, which findBy() cannot filter on.
     */
    public function findDrafts(): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.labels', 'l')
            ->where('l.role = :drafts')
            ->setParameter('drafts', LabelRole::Drafts)
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every draft a user owns, on every account.
     *
     * Selected by Drafts-role label the way the Drafts list selects them, so
     * Gmail-style drafts with no mailbox are covered — a join over a to-many
     * association plus a join to the owning account, neither of which findBy()
     * can express.
     *
     * @return list<Message>
     */
    public function findDraftsForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.account', 'a')
            ->innerJoin('m.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('l.role = :drafts')
            ->setParameter('user', $user)
            ->setParameter('drafts', LabelRole::Drafts)
            ->getQuery()
            ->getResult();
    }

    /**
     * Stream the address fields of every message belonging to an account — via
     * its mailbox (IMAP) or its thread (Gmail-API messages carry no mailbox
     * row).
     *
     * QueryBuilder twice over: the OR spans two joined associations, and the
     * result is streamed rather than returned, which findBy() cannot do at all.
     * Streaming is the point — an account's whole mail does not fit in memory.
     *
     * Five columns rather than the entity, and that is the whole reason this
     * returns arrays. The only caller reads from/to/cc/bcc; hydrating Message
     * also fetched body_html, body_text, body_html_safe, headers and the
     * search_vector — the five widest columns in the table — and discarded
     * them. On a fifty-thousand-message account that is most of a mailbox read
     * off disk to learn some addresses. Scalars also keep the unit of work
     * empty, which a toIterable() over entities does not.
     *
     * @return iterable<array{fromAddress: ?string, fromName: ?string, toAddresses: ?array<mixed>, ccAddresses: ?array<mixed>, bccAddresses: ?array<mixed>}>
     */
    public function iterateAddressesForAccount(Account $account): iterable
    {
        return $this->createQueryBuilder('message')
            ->select(
                'message.fromAddress AS fromAddress',
                'message.fromName AS fromName',
                'message.toAddresses AS toAddresses',
                'message.ccAddresses AS ccAddresses',
                'message.bccAddresses AS bccAddresses',
            )
            // EXISTS rather than a join: one row per address set, however many
            // folders the message is kept in.
            ->leftJoin('message.thread', 'thread')
            ->where('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l JOIN l.mailbox mailbox WHERE l.message = message AND mailbox.account = :account) OR thread.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->toIterable();
    }

    /**
     * How many messages are recorded as sitting in this folder.
     *
     * Asked before a Mailbox row is removed, and the answer has to be zero:
     * message.mailbox_id cascades on delete, so removing the row of a folder
     * that still has mail recorded in it deletes that mail here.
     *
     * Messages, not locations: a message with two copies in one folder is
     * still one message that would be lost.
     */
    public function countInMailbox(Mailbox $mailbox): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(l.message))')
            ->from(MessageLocation::class, 'l')
            ->where('l.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A thread's messages in the order the conversation actually happened.
     *
     * Received mail is placed by when it arrived, sent mail by when it was
     * sent, and a bare draft by when it was written — `received_at`, then
     * `sent_at`, then `created_at`. The mapped `messages` association can only
     * order by `receivedAt`, which is null on everything this account sent, so
     * Postgres sorted every reply to the very bottom of its own thread, under
     * mail that had arrived after it. Ordered here instead, with no stored
     * duplicate of a value the three columns already hold.
     *
     * COALESCE cannot sit in a DQL ORDER BY directly, so it is selected as a
     * HIDDEN alias — computed and ordered on, never hydrated into the result.
     *
     * @return list<Message>
     */
    public function forThreadInConversationOrder(MessageThread $thread): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('COALESCE(m.receivedAt, m.sentAt, m.createdAt) AS HIDDEN effectiveAt')
            ->where('m.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('effectiveAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * forThreadInConversationOrder() plus what the reading pane reads off each
     * message — its label chips and its attachment chips — so a conversation
     * costs the same number of queries at two messages as at twenty.
     *
     * Labels are fetch-joined into the one statement; parts are NOT, because a
     * second to-many join multiplies the first (labels × parts rows, every one
     * carrying the bodies). They are a statement of their own instead, for
     * every message of the thread. It used to be only those with
     * hasAttachments, because the pane touched no other message's parts; since
     * phase 6g of #28 it does — a message whose one attachment is the sender's
     * own key has hasAttachments false and still says that key in its banner
     * (AttachedKeyExtension) — and a part-less message asked lazily is one
     * query per message, which the budget test catches.
     *
     * @return list<Message>
     */
    public function forThreadView(MessageThread $thread): array
    {
        /** @var list<Message> $messages */
        $messages = $this->createQueryBuilder('m')
            ->addSelect('l')
            ->leftJoin('m.labels', 'l')
            ->addSelect('COALESCE(m.receivedAt, m.sentAt, m.createdAt) AS HIDDEN effectiveAt')
            ->where('m.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('effectiveAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        $ids = array_values(array_filter(array_map(static fn (Message $message): ?int => $message->id, $messages)));

        if ([] !== $ids) {
            // Already managed, so this only initialises messageParts on them —
            // an empty collection too, for a message without any.
            $this->createQueryBuilder('m')
                ->addSelect('p')
                ->leftJoin('m.messageParts', 'p')
                ->where('m.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();
        }

        return $messages;
    }

    /**
     * The handful of columns a conversation row reads from each of its
     * thread's messages, for every thread on a list page in one statement.
     *
     * Fields rather than entities, and that is the point: hydrating Message
     * here pulled every body (text, raw HTML, sanitised HTML), the header blob
     * and the search vector of every message on the page into PHP, to read a
     * sender and a date off each. See App\Service\Mail\ThreadRows.
     *
     * ORDERED the way the association is (#[ORM\OrderBy(receivedAt, id)]),
     * because the row's "latest" and "draft" are both "the last one of these".
     *
     * @param list<int> $threadIds
     *
     * @return list<array<string, mixed>>
     */
    public function findRowFieldsForThreads(array $threadIds): array
    {
        if ([] === $threadIds) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return $this->createQueryBuilder('m')
            ->select(
                'm.id',
                'IDENTITY(m.thread) AS threadId',
                'm.fromAddress',
                'm.fromName',
                'm.toAddresses',
                'm.flags',
                'm.receivedAt',
                'm.sentAt',
                'm.createdAt',
                'm.submissionSendAt',
                // The warning a draft whose send failed wears in the list (#125).
                'm.sendFailure',
                // Whether the message is filed under Sent or Drafts, for
                // ThreadRowMessage::$filedAsSent. A count in the same statement
                // rather than a join: a join would repeat the row per label,
                // and a second statement would be one more query on every list
                // page for a column most rows never have read.
                '(SELECT COUNT(sentLabel.id) FROM ' . Message::class . ' filed
                    JOIN filed.labels sentLabel
                    WHERE filed.id = m.id AND sentLabel.role IN (:sentRoles)) AS filedAsSent',
                // Whether it is in the bin, for the side of a partly deleted
                // conversation the row shows (BinView). Counted the same way.
                '(SELECT COUNT(trashLabel.id) FROM ' . Message::class . ' inBin
                    JOIN inBin.labels trashLabel
                    WHERE inBin.id = m.id AND trashLabel.role = :trashRole) AS trashed',
                'm.seenAt',
                'm.hasAttachments',
            )
            ->where('m.thread IN (:threads)')
            ->setParameter('sentRoles', [LabelRole::Sent, LabelRole::Drafts])
            ->setParameter('trashRole', LabelRole::Trash)
            ->setParameter('threads', $threadIds)
            ->addOrderBy('m.receivedAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * The stored list previews of specific messages — one per list row, the
     * row's latest — with what decides whether each still fits: its key, the
     * message's category, and whether it is encrypted (the lock in front of
     * the row's subject, ThreadRow::$encrypted). See Message::$preview.
     *
     * No body columns: not fetching them is half of what the stored preview
     * saves. Only the rows whose preview is missing or stale go on to
     * findSnippetBodies().
     *
     * @param list<int> $ids
     *
     * @return array<int, array{preview: ?string, previewKey: ?int, category: ?MessageCategory, crypto: ?MessageCrypto}>
     */
    public function findPreviews(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string, preview: ?string, previewKey: int|string|null, category: MessageCategory|string|null, crypto: ?MessageCrypto}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'm.preview', 'm.previewKey', 'm.category', 'm.crypto')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $previews = [];

        foreach ($rows as $row) {
            $category = $row['category'];

            $previews[(int) $row['id']] = [
                'preview'    => $row['preview'],
                'previewKey' => null === $row['previewKey'] ? null : (int) $row['previewKey'],
                'category'   => is_string($category) ? MessageCategory::tryFrom($category) : $category,
                'crypto'     => $row['crypto'],
            ];
        }

        return $previews;
    }

    /**
     * Keep the previews a list just made, so the next list reads them.
     *
     * One statement for the page, and straight to the table rather than
     * through the entities: these messages were never hydrated, and a cache
     * filled from a read is no reason to load fifty of them. The columns are
     * outside every trigger on `message`, search_vector's included.
     *
     * Best effort. A preview that could not be kept is made again next time,
     * so a failure here — a lock, a connection gone between two statements —
     * must not fail the list it was only saving work for. In a transaction of
     * its own, which inside somebody else's is a savepoint: a failed statement
     * in Postgres poisons the transaction it ran in, and swallowing the error
     * without rolling back to before it would fail every query after it.
     *
     * A body replaced between the read that made a preview and this write
     * would get the old body's line until the next change to it; bodies are
     * replaced rarely (a decrypted copy handed over, a repair), and that
     * window is one request long.
     *
     * @param array<int, array{string, int}> $previews message id => [preview, key]
     */
    public function storePreviews(array $previews): void
    {
        if ([] === $previews) {
            return;
        }

        $values = [];
        $params = [];

        foreach ($previews as $id => [$preview, $key]) {
            $values[] = '(?::int, ?::text, ?::smallint)';
            array_push($params, $id, $preview, $key);
        }

        $connection = $this->getEntityManager()->getConnection();

        try {
            $connection->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
                'UPDATE message AS m SET preview = v.preview, preview_key = v.preview_key
                 FROM (VALUES ' . implode(', ', $values) . ') AS v(id, preview, preview_key)
                 WHERE m.id = v.id',
                $params,
            ));
        } catch (\Doctrine\DBAL\Exception) {
            // Made again on the next list; see above.
        }
    }

    /**
     * The columns MessageSnippet reads, for specific messages only — one per
     * list row, the row's latest. The category decides whether the preview
     * may skip a bulk mail's chrome; whether it is encrypted is the lock in
     * front of the row's subject (ThreadRow::$encrypted).
     *
     * @param list<int> $ids
     *
     * @return array<int, array{bodyHtmlSafe: ?string, bodyText: ?string, category: ?MessageCategory, crypto: ?MessageCrypto}>
     */
    public function findSnippetBodies(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string, bodyHtmlSafe: ?string, bodyText: ?string, category: MessageCategory|string|null, crypto: ?MessageCrypto}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'm.bodyHtmlSafe', 'm.bodyText', 'm.category', 'm.crypto')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $bodies = [];

        foreach ($rows as $row) {
            $category = $row['category'];

            $bodies[(int) $row['id']] = [
                'bodyHtmlSafe' => $row['bodyHtmlSafe'],
                'bodyText'     => $row['bodyText'],
                'category'     => is_string($category) ? MessageCategory::tryFrom($category) : $category,
                'crypto'       => $row['crypto'],
            ];
        }

        return $bodies;
    }

    /**
     * Unread mail stored since $since in this user's active accounts — what
     * NotifyScopes::hasRecentUnread() sorts into switches.
     *
     * By when plMail STORED it (createdAt), not by the date it carries: the
     * question is about mail a push has just announced, and a message stamped
     * by a sender's slow clock arrived just now all the same. createdAt has no
     * index, though, so the date it carries narrows the search first, through
     * idx_message_account_received_at, with a day of slack for that slow
     * clock. A message dated more than a day before it arrived is missed, and
     * the cost of that is one notification not shown.
     *
     * Bounded on purpose. The answer is "is there at least one", so a few
     * dozen candidates are plenty. Labels are not fetch-joined for the same
     * reason: a LIMIT over a joined collection limits rows, not messages.
     *
     * @return list<Message>
     */
    public function findRecentUnreadForUser(User $user, DateTimeImmutable $since, int $limit = 50): array
    {
        /** @var list<Message> */
        return $this->createQueryBuilder('m')
            ->join('m.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.seenAt IS NULL')
            ->andWhere('m.receivedAt >= :dated')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('dated', $since->modify('-1 day'))
            ->setParameter('since', $since)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The newest PGP-signed messages a person received from one address,
     * newest first — what SignatureRecheck checks again once a key for that
     * address is added, pinned or replaced.
     *
     * Only messages whose signature the server checks: signed, not encrypted.
     * The browser checks the signature inside an encrypted message every time
     * it opens one, so there is nothing stored to bring up to date.
     *
     * @return list<int>
     */
    public function findPgpSignedIdsFrom(User $user, string $email, int $limit): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->join('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('LOWER(m.fromAddress) = :email')
            ->andWhere('m.crypto IN (:crypto)')
            ->setParameter('usr', $user)
            ->setParameter('email', strtolower(trim($email)))
            ->setParameter('crypto', [MessageCrypto::PgpSigned->value, MessageCrypto::PgpInlineSigned->value])
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The newest message with this subject in any of these accounts, and only
     * an unsent one when asked: how the E2E commands find what a spec wrote.
     *
     * @param list<Account> $accounts
     */
    public function findLatestBySubject(array $accounts, string $subject, bool $unsentOnly = false): ?Message
    {
        if ([] === $accounts) {
            return null;
        }

        $criteria = ['account' => $accounts, 'subject' => $subject];

        if (true === $unsentOnly) {
            $criteria['sentAt'] = null;
        }

        return $this->findOneBy($criteria, ['id' => 'DESC']);
    }
}
