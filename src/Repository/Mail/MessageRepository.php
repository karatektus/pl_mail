<?php

namespace App\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Query\CompiledFilter;
use App\Service\Graph\GraphMessageBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    /**
     * Content types that mean "there is an invite in here".
     *
     * @var list<string>
     */
    private const array CALENDAR_TYPES = ['text/calendar', 'application/ics'];

    /**
     * Messages that could plausibly carry an event.
     *
     * A mailbox is mostly newsletters, and parsing every one to find the few
     * per cent that are bookings is work nobody gets back. Three signals, one
     * per extractor: a text/calendar part (an invite, on IMAP or Gmail), the
     * synthetic Graph meeting header (an invite with no part to find), and
     * schema.org markup in the raw body (a reservation).
     *
     * Raw DBAL because none of the three is expressible otherwise: jsonb key
     * existence has no DQL operator and no registered function, and the rest
     * is an EXISTS correlated to a second entity. Written as jsonb_exists()
     * rather than the `?` operator that means the same thing — DBAL reads a
     * bare `?` as a positional placeholder and refuses the query — and cast,
     * because message.headers is a json column rather than jsonb. The
     * parameters are namespaced so they cannot collide if this is ever
     * combined with a compiled filter, as MessageRepository::matchingIds does.
     *
     * This is the WHERE both candidate queries share, so a change to what
     * counts as a candidate cannot land in one and not the other. Built by
     * concatenation rather than by editing a finished query — the first version
     * pulled the LIMIT off with str_replace and silently stopped matching the
     * moment the heredoc's indentation changed.
     */
    private const string EXTRACTION_CANDIDATE_WHERE = <<<'SQL'
        m.id > :extAfterId
        AND (
              EXISTS (
                  SELECT 1 FROM message_part p
                  WHERE p.message_id = m.id
                    AND LOWER(p.content_type) IN (:extCalendarTypes)
              )
           OR jsonb_exists(m.headers::jsonb, :extMeetingHeader)
           OR m.body_html LIKE :extJsonLd
        )
        SQL;

    /** Raw DBAL for the reasons EXTRACTION_CANDIDATE_WHERE gives above. */
    public function countExtractionCandidates(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM message m WHERE ' . self::EXTRACTION_CANDIDATE_WHERE,
            $this->candidateParameters(0),
            $this->candidateTypes(),
        );
    }

    /**
     * @return list<Message>
     */
    public function extractionCandidates(int $afterId, int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT m.id FROM message m WHERE ' . self::EXTRACTION_CANDIDATE_WHERE
            . ' ORDER BY m.id ASC LIMIT ' . max(1, $limit),
            $this->candidateParameters($afterId),
            $this->candidateTypes(),
        );

        if (0 === count($ids)) {
            return [];
        }

        return $this->findBy(['id' => array_map('intval', $ids)], ['id' => 'ASC']);
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateParameters(int $afterId): array
    {
        return [
            'extAfterId'       => $afterId,
            'extCalendarTypes' => self::CALENDAR_TYPES,
            'extMeetingHeader' => GraphMessageBuilder::MEETING_TYPE_HEADER,
            'extJsonLd'        => '%application/ld+json%',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateTypes(): array
    {
        return [
            'extAfterId'       => ParameterType::INTEGER,
            'extCalendarTypes' => ArrayParameterType::STRING,
            'extMeetingHeader' => ParameterType::STRING,
            'extJsonLd'        => ParameterType::STRING,
        ];
    }

    /**
     * Which of these messages a compiled filter matches.
     *
     * This is the single place a rule's conditions are ever evaluated. Letting
     * Postgres answer means there is exactly one implementation of what a
     * filter means — an in-memory twin was tried and deleted, because two
     * implementations can drift and the symptom is mail quietly filed in the
     * wrong place. It also makes `text` usable in rules: search_vector is a
     * STORED generated column, so full-text works here and could never be
     * reproduced faithfully in PHP.
     *
     * The messages must already be flushed — they are, since rules run after
     * the id-granting flush in every sync path.
     *
     * @param list<int> $messageIds
     *
     * @return list<int> ids that match, in the order given
     */
    public function matchingIds(array $messageIds, CompiledFilter $filter): array
    {
        if (0 === count($messageIds)) {
            return [];
        }

        $sql = sprintf(
            'SELECT m.id FROM message m WHERE m.id IN (:ruleMessageIds) AND (%s)',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleMessageIds'] = $messageIds;

        $types = $filter->parameterTypes();
        $types['ruleMessageIds'] = ArrayParameterType::INTEGER;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $types)
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * How many of a user's messages a compiled filter matches.
     *
     * Powers the live "matches N messages" readout while a rule is being
     * written. Capped, because the only question being answered is "is this
     * filter roughly right" — an exact count over a large mailbox costs a full
     * scan to tell the author something they do not need.
     *
     * @return array{count: int, capped: bool}
     */
    public function countMatchingForUser(
        User $user,
        CompiledFilter $filter,
        int $cap = 500,
        ?Account $account = null,
    ): array {
        // Null account is the rule's own "every account", so the absence of
        // the clause is the scope rather than a missing one.
        $scope = null === $account ? '' : ' AND a.id = :ruleAccountId';

        $sql = sprintf(
            'SELECT COUNT(*) FROM (
                 SELECT 1 FROM message m
                 JOIN account a ON a.id = m.account_id
                 WHERE a.usr_id = :ruleUserId%s AND (%s)
                 LIMIT :ruleCap
             ) probe',
            $scope,
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleUserId'] = $user->id;
        $parameters['ruleCap'] = $cap + 1;

        if (null !== $account) {
            $parameters['ruleAccountId'] = $account->id;
        }

        $count = (int) $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $filter->parameterTypes())
            ->fetchOne();

        return [
            'count' => min($count, $cap),
            'capped' => $count > $cap,
        ];
    }

    /**
     * One page of a user's messages matching a filter, for applying a rule to
     * mail that arrived before it existed.
     *
     * Keyset pagination rather than a cap: applying a rule must reach every
     * matching message, and a mailbox can hold more of them than fits in
     * memory. Paging by id also survives the run's own writes — an OFFSET walk
     * would skip messages as the rows it already acted on stop matching.
     *
     * @return list<int> ascending, empty when the walk is finished
     */
    public function findIdsMatchingForUser(User $user, CompiledFilter $filter, int $afterId = 0, int $batchSize = 200): array
    {
        $sql = sprintf(
            'SELECT m.id FROM message m
             JOIN account a ON a.id = m.account_id
             WHERE a.usr_id = :ruleUserId AND m.id > :ruleAfterId AND (%s)
             ORDER BY m.id ASC
             LIMIT :ruleBatchSize',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleUserId'] = $user->id;
        $parameters['ruleAfterId'] = $afterId;
        $parameters['ruleBatchSize'] = $batchSize;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $filter->parameterTypes())
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * Ids and thread ids of every message in an account matching a compiled
     * JMAP filter, in the requested order — the whole of Email/query's read.
     *
     * Raw SQL for the same reason matchingIds() is: the filter arrives as a SQL
     * fragment compiled from the client's request, because Postgres is the only
     * implementation of what a JMAP filter means. Two integer columns are
     * selected and nothing is hydrated; the spec's `position` and `total` are
     * defined over the collapsed list, so the caller windows in PHP.
     *
     * $orderBySql is interpolated because ORDER BY takes expressions, not bound
     * values. It is safe by construction: EmailQueryRunner builds it from a
     * fixed property→column map and raises unsupportedSort for anything absent
     * from it, so no client string ever reaches this.
     *
     * @return list<array{id: int|string, thread_id: int|string|null}>
     */
    public function findIdsForQuery(int $accountId, ?CompiledFilter $filter, string $orderBySql): array
    {
        $parameters = ['accountId' => $accountId];
        $types      = [];
        $where      = 'm.account_id = :accountId';

        if (null !== $filter) {
            $where     .= ' AND '.$filter->sql;
            $parameters = array_merge($parameters, $filter->parameters);
            $types      = $filter->parameterTypes();
        }

        /** @var list<array{id: int|string, thread_id: int|string|null}> */
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery(
                sprintf('SELECT m.id, m.thread_id FROM message m WHERE %s ORDER BY %s', $where, $orderBySql),
                $parameters,
                $types,
            )
            ->fetchAllAssociative();
    }

    /**
     * Highlighted subject and body fragments for a SearchSnippet/get.
     *
     * Raw SQL because `ts_headline` is the whole point — it is a Postgres
     * full-text function with no DQL equivalent, and reproducing "which words
     * matched, in context" in PHP would be a second, disagreeing implementation
     * of the search that produced the hits.
     *
     * @param list<int> $ids
     *
     * @return list<array{id: int|string, subject: mixed, preview: mixed}>
     */
    public function findSearchHeadlines(int $accountId, array $ids, string $text, string $headlineOptions): array
    {
        if (0 === count($ids)) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                m.id,
                ts_headline('english', coalesce(m.subject, ''),
                    websearch_to_tsquery('english', :text), :options) AS subject,
                ts_headline('english', coalesce(m.body_text, ''),
                    websearch_to_tsquery('english', :text), :options) AS preview
            FROM message m
            WHERE m.account_id = :account
              AND m.id IN (:ids)
            SQL;

        /** @var list<array{id: int|string, subject: mixed, preview: mixed}> */
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [
                'text'    => $text,
                'options' => $headlineOptions,
                'account' => $accountId,
                'ids'     => $ids,
            ],
            [
                'ids' => ArrayParameterType::INTEGER,
            ],
        );
    }

    /**
     * Per-label message totals and unread counts for one account, in one
     * grouped query — the numbers behind a Mailbox/get.
     *
     * DBAL rather than DQL: this is a pure aggregate over the message↔label
     * join table, nothing is hydrated, and counting per label through the ORM
     * over a large label tree is a textbook N+1.
     *
     * Unread is "seen_at IS NULL", not the absence of the \Seen entry in
     * Message::$flags. The two disagree: flags is an IMAP mirror that only the
     * plain-IMAP sync path populates, so it is a strict subset of seen_at.
     * seen_at is the field the web UI reads and writes, so it is authoritative.
     *
     * @return array<int,array{total:int,unread:int}> label id => counts
     */
    public function countEmailsPerLabelForAccount(int $accountId): array
    {
        return $this->labelCounts($accountId, <<<'SQL'
            SELECT ml.label_id,
                   COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
            GROUP BY ml.label_id
            SQL);
    }

    /**
     * The same per label, counting distinct threads instead of messages.
     *
     * Counted through message_label rather than thread_label so both grains
     * stay consistent by construction — a thread is in a mailbox exactly when
     * one of its messages is.
     *
     * Note this reads unreadThreads as "threads with an unread Email *in this
     * mailbox*". RFC 8621 defines it slightly more loosely (an unread Email
     * anywhere in the Thread). The stricter reading is what the plMail UI
     * shows, and it cannot exceed totalThreads, which is what clients assert.
     *
     * @return array<int,array{total:int,unread:int}> label id => counts
     */
    public function countThreadsPerLabelForAccount(int $accountId): array
    {
        return $this->labelCounts($accountId, <<<'SQL'
            SELECT ml.label_id,
                   COUNT(DISTINCT m.thread_id) AS total,
                   COUNT(DISTINCT m.thread_id) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
              AND m.thread_id IS NOT NULL
            GROUP BY ml.label_id
            SQL);
    }

    /**
     * @return array<int,array{total:int,unread:int}>
     */
    private function labelCounts(int $accountId, string $sql): array
    {
        $counts = [];

        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['accountId' => $accountId]) as $row) {
            $counts[(int) $row['label_id']] = [
                'total'  => (int) $row['total'],
                'unread' => (int) $row['unread'],
            ];
        }

        return $counts;
    }

    /**
     * Ids of messages that have a header bag, oldest first — the backfill
     * cursor for header normalisation.
     *
     * QueryBuilder because this is keyset pagination over a projection:
     * findBy() can neither express `id > :afterId` nor return bare ids, and
     * hydrating a batch of entities to read one column off each is exactly the
     * cost a backfill cursor exists to avoid.
     *
     * @return list<int>
     */
    public function findIdsWithHeaders(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.headers IS NOT NULL')
            ->andWhere('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Ids of every message, oldest first — the backfill cursor for tasks that
     * rewrite a column present on every row (address normalisation).
     *
     * Keyset over a projection, for the reason findIdsWithHeaders() gives.
     *
     * @return list<int>
     */
    public function findIdsAfter(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Every message id of an account, for a task that has to walk all of them.
     *
     * Ids rather than entities, and by arrival when the caller is replaying
     * history: the rethread backfill clears the EntityManager on every batch,
     * which would invalidate a cursor held across it.
     *
     * QueryBuilder for the projection — findBy() returns entities, which is the
     * one thing this must not do.
     *
     * @return list<int>
     */
    public function findAllIdsForAccount(int $accountId, bool $orderByArrival = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.account = :accountId')
            ->setParameter('accountId', $accountId);

        if (true === $orderByArrival) {
            $qb->orderBy('m.receivedAt', 'ASC');
        }

        $qb->addOrderBy('m.id', 'ASC');

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $qb->getQuery()->getArrayResult(),
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Message>
     */
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
     * Cut every message of an account loose from its thread.
     *
     * Deliberately a single UPDATE and deliberately not the ORM: MessageThread
     * cascades remove onto its messages, so detaching by walking the
     * association and then removing threads through the EntityManager would
     * delete the mail along with them.
     */
    public function detachAllFromThreadsForAccount(int $accountId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message SET thread_id = NULL WHERE account_id = :accountId',
            ['accountId' => $accountId],
        );
    }

    /**
     * Messages with an HTML body but no sanitized copy — the safe-html
     * backfill's cursor.
     *
     * QueryBuilder because `bodyHtml <> ''` and `id > :afterId` are comparisons
     * findBy() cannot state. Keyset by id rather than a shrinking IS NULL
     * cursor so the scan cannot loop on rows the sanitizer legitimately leaves
     * null (whitespace-only bodies).
     *
     * @return list<Message>
     */
    public function findPendingSafeHtml(int $afterId, int $limit): array
    {
        return $this->pendingSafeHtmlQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countPendingSafeHtml(): int
    {
        return (int) $this->pendingSafeHtmlQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function pendingSafeHtmlQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            ->andWhere("m.bodyHtml <> ''")
            ->andWhere('m.bodyHtmlSafe IS NULL')
            ->setParameter('afterId', $afterId);
    }

    /**
     * Messages whose HTML body carries a charset declaration of its own — the
     * set the safe-html re-tag repair has to walk.
     *
     * bodyHtmlSafe is derived, so a row mangled by a declaration the parser
     * believed is repaired by sanitising it again; bodyHtml itself was never
     * wrong. Narrowed by the substring rather than the damage because the
     * damage is not something SQL can recognise, and re-sanitising a row that
     * was already right produces the same bytes it already has.
     *
     * @return list<Message>
     */
    public function findWithHtmlCharsetDeclaration(int $afterId, int $limit): array
    {
        return $this->htmlCharsetDeclarationQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithHtmlCharsetDeclaration(): int
    {
        return (int) $this->htmlCharsetDeclarationQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function htmlCharsetDeclarationQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            ->andWhere("m.bodyHtml <> ''")
            // LIKE is case-sensitive in Postgres and the tag is written every
            // way there is, so the comparison is folded rather than the tag
            // guessed at.
            ->andWhere("LOWER(m.bodyHtml) LIKE '%charset%'")
            ->setParameter('afterId', $afterId);
    }

    /**
     * One account's messages awaiting categorisation — everything when the
     * classifier itself changed, otherwise only the rows that have no category
     * yet.
     *
     * QueryBuilder for the keyset bound, as with the safe-html walk.
     *
     * @return list<Message>
     */
    public function findPendingCategorization(int $accountId, bool $includeCategorized, int $afterId, int $limit): array
    {
        return $this->pendingCategorizationQueryBuilder($accountId, $includeCategorized, $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countPendingCategorization(int $accountId, bool $includeCategorized): int
    {
        return (int) $this->pendingCategorizationQueryBuilder($accountId, $includeCategorized, 0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function pendingCategorizationQueryBuilder(int $accountId, bool $includeCategorized, int $afterId): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.account = :accountId')
            ->andWhere('m.id > :afterId')
            ->setParameter('accountId', $accountId)
            ->setParameter('afterId', $afterId);

        if (false === $includeCategorized) {
            $qb->andWhere('m.category IS NULL');
        }

        return $qb;
    }

    /**
     * Every raw-message path still pointed at by a row, as a lookup set — the
     * "keep" list for the blob sweep.
     *
     * One streamed sequential scan rather than a batched IN() per thousand
     * files: raw_path is not indexed, and indexing it to serve a maintenance
     * command would tax every write to buy nothing the rest of the year. The
     * LIKE keeps provider-scheme values (gmail://, msgraph://) out of the set,
     * since those name no local file.
     *
     * @return array<string, true>
     */
    public function findReferencedRawPaths(string $pathPrefix): array
    {
        return $this->referencedPathSet('SELECT raw_path FROM message WHERE raw_path LIKE :prefix', $pathPrefix);
    }

    /**
     * @return array<string, true>
     */
    private function referencedPathSet(string $sql, string $pathPrefix): array
    {
        $referenced = [];

        $result = $this->getEntityManager()->getConnection()->executeQuery($sql, ['prefix' => $pathPrefix.'/%']);

        foreach ($result->iterateColumn() as $path) {
            $referenced[$path] = true;
        }

        return $referenced;
    }

    /**
     * Every UID this mailbox has already stored.
     *
     * QueryBuilder for the projection: the syncer diffs a set of integers
     * against what the server offers, and hydrating a Message per UID to read
     * one column would make a routine poll cost a mailbox load.
     */
    public function findSyncedUids(Mailbox $mailbox): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.imapUid')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Every addressable row this mailbox holds, as UID => Message.
     *
     * Hydrated, unlike findLocatedUidsById(), and keyed the other way round,
     * because the caller's question is the other way round: the flag pass holds
     * the server's answer keyed by UID and needs the row that answer is about,
     * in order to change it. There is no bulk-update shortcut available to it —
     * applying a flag means seenAt, starredAt, the thread's unread count and a
     * JMAP change-log row, which is ThreadStatusUpdater's job and needs
     * entities.
     *
     * The cost is bounded by the same thing that bounds the sweep: this runs
     * once per folder per SWEEP_INTERVAL_MINUTES, against the folder's located
     * rows, and the listing it is compared with is the expensive half.
     *
     * @return array<int,Message> imapUid => Message
     */
    public function findLocatedByUid(Mailbox $mailbox): array
    {
        /** @var list<Message> $rows */
        $rows = $this->createQueryBuilder('m')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getResult();

        $located = [];

        foreach ($rows as $row) {
            $located[(int) $row->imapUid] = $row;
        }

        return $located;
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
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'm.imapUid')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
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
     * The rows behind a set of Gmail ids, scoped to one owner.
     *
     * Scoped through the user rather than the account for the same reason
     * findSyncedGmailIdsForUser() is: plMail attributes a Gmail message to
     * whichever of the owner's accounts it was actually addressed to, so the
     * row for an id that arrived on one account's history feed may well hang
     * off a sibling.
     *
     * @param list<string> $gmailIds
     *
     * @return list<Message>
     */
    public function findByGmailIdsForUser(User $user, array $gmailIds): array
    {
        if (0 === count($gmailIds)) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId IN (:gmailIds)')
            ->setParameter('usr', $user)
            ->setParameter('gmailIds', $gmailIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * QueryBuilder on two counts: the owner is reached through the account
     * association, which findBy() cannot traverse, and only one column comes
     * back.
     */
    public function findSyncedGmailIdsForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.gmailId')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId IS NOT NULL')
            ->setParameter('usr', $user)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Joined via thread since Gmail-API messages carry no mailbox — a join
     * across an association, which findOneBy() has no way to state.
     */
    public function findOneByMessageIdsForAccount(array $messageIds, Account $account): ?Message
    {
        if (count($messageIds) === 0) {
            return null;
        }

        return $this->createQueryBuilder('message')
            ->innerJoin('message.thread', 'thread')
            ->where('thread.account = :account')
            ->andWhere('message.messageId IN (:messageIds)')
            ->setParameter('account', $account)
            ->setParameter('messageIds', $messageIds)
            ->orderBy('message.receivedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsWithFromAddressInThread(string $fromAddress, MessageThread $thread): bool
    {
        return $this->existsWithAnyFromAddressInThread([$fromAddress], $thread);
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

        return $result !== null;
    }

    /** A null criterion is Doctrine's IS NULL, so this needs no query of its own. */
    public function countUnseenForMailbox(Mailbox $mailbox): int
    {
        return $this->count(['mailbox' => $mailbox, 'seenAt' => null]);
    }

    public function countTotalForMailbox(Mailbox $mailbox): int
    {
        return $this->count(['mailbox' => $mailbox]);
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
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('mailbox.account = :account OR thread.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->toIterable();
    }

    /**
     * The account's own copy of a message (via mailbox or thread ownership)
     * by canonical RFC Message-ID — the enrichment target for Gmailify dedup.
     *
     * The OR across two joins is what findOneBy() cannot say: ownership lives
     * on whichever of the two associations this message happens to have.
     */
    public function findOneForAccountByMessageId(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('mailbox.account = :account OR thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
     */
    public function findUnlocatedByMessageId(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('message.imapUid IS NULL')
            ->andWhere('mailbox.account = :account OR thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
     * @return list<string>
     */
    public function findMessageIdsAlsoFiledElsewhere(Mailbox $mailbox, int $limit): array
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
            ->groupBy('message.messageId')
            ->setParameter('mailbox', $mailbox)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn ($id): string => (string) $id, $rows));
    }

    /**
     * Streamed, so recategorising a large account does not load it into memory
     * — the reason this cannot be findBy().
     *
     * @return iterable<Message>
     */
    public function iterateForRecategorization(Account $account, bool $includeCategorized): iterable
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.account = :account')
            ->setParameter('account', $account);

        if (false === $includeCategorized) {
            $qb->andWhere('m.category IS NULL');
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * QueryBuilder for the join to the owning user and for the single-column
     * projection, as with findSyncedGmailIdsForUser().
     *
     * @return list<string>
     */
    public function findSyncedGraphIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.graphId')
            ->join('m.account', 'a')
            ->andWhere('a.usr = :user')
            ->andWhere('m.graphId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map('strval', array_column($rows, 'graphId')));
    }

    /**
     * JMAP Email/get: fetch by id, scoped to the account so a foreign id can
     * never resolve. Labels and parts are eager-joined because the mapper
     * touches both for every message.
     *
     * The fetch-join is the reason this is not findBy(): findBy() honours the
     * mapped fetch mode, so each message's labels and parts would be lazy-loaded
     * one query at a time — the N+1 an Email/get over a page of ids exists to
     * avoid.
     *
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    public function findByAccountAndIds(int $accountId, array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->addSelect('l', 'p')
            ->leftJoin('m.labels', 'l')
            ->leftJoin('m.messageParts', 'p')
            ->where('m.account = :account')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('account', $accountId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids of one account's messages carrying one label, by keyset.
     *
     * For re-tagging after an Exchange master category is renamed: Exchange
     * stores a category on each message as a string, so the messages have to be
     * pushed again or they go on carrying the old name. See
     * ApplyLabelStructureHandler.
     *
     * Ids rather than entities, and keyset rather than OFFSET: the population is
     * "every message that has ever had this label", which on a busy mailbox is
     * unbounded, and both hydrating it and paging it by offset degrade as it
     * grows. The caller walks with the last id it saw.
     *
     * Only messages the provider knows about — a message with no graphId has
     * never been at Exchange and there is nothing there to re-tag.
     *
     * @return list<int>
     */
    public function findIdsWithLabelForAccount(
        int $accountId,
        int $labelId,
        int $afterId = 0,
        int $limit = 200,
    ): array {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->innerJoin('m.labels', 'l')
            ->where('m.account = :account')
            ->andWhere('l.id = :label')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.graphId IS NOT NULL')
            ->setParameter('account', $accountId)
            ->setParameter('label', $labelId)
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
