<?php

namespace App\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Query\CompiledFilter;
use App\Service\Graph\GraphMessageBuilder;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
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
     * Take ownership of a message for sending, or report that it is not ours.
     *
     * The whole point is that this is ONE statement. SendMessageHandler used to
     * read `cancelled` and then send, which is two, and an undo that committed
     * between them vanished without trace — the mail went out and the user had
     * already been told it was cancelled. Here the guard and the claim happen
     * in the same UPDATE, so the answer to "may I send this?" is the same event
     * as "nobody else may cancel it now", and Postgres is what decides.
     *
     * The three conditions are the three ways a send can be illegitimate: it
     * was cancelled, it already left, or another worker holds it. Returning
     * false covers all three, and the caller does not need to know which —
     * every one of them means "do not send".
     *
     * Deliberately raw DBAL and deliberately not going through the ORM: an
     * entity write would be a read-modify-write through the identity map, which
     * is exactly the shape that lost the race in the first place.
     *
     * With $pinsSendAt the claim also requires submission_send_at to still be
     * $sendAt — the release time the envelope was dispatched for. A fourth way
     * a send can be illegitimate: it was rescheduled, and this envelope is the
     * old schedule's. See SendMessageMessage. IS NOT DISTINCT FROM because
     * NULL is a value here (a composer send has no submission time) and `=`
     * would never match it.
     */
    public function claimForSend(int $messageId, bool $pinsSendAt = false, ?DateTimeImmutable $sendAt = null): bool
    {
        $parameters = ['id' => $messageId];
        $types      = [];
        $schedule   = '';

        if (true === $pinsSendAt) {
            $schedule             = 'AND submission_send_at IS NOT DISTINCT FROM :sendAt';
            $parameters['sendAt'] = $sendAt;
            $types['sendAt']      = Types::DATETIME_IMMUTABLE;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            // The database's own clock, not PHP's: the claim is decided here,
            // so the timestamp that records it should be too.
            'UPDATE message
                SET send_claimed_at = LOCALTIMESTAMP
              WHERE id = :id
                AND cancelled = false
                AND sent_at IS NULL
                ' . $schedule . '
                AND (
                    send_claimed_at IS NULL
                    -- A claim is "a handler has this right now", and a handler
                    -- that was killed between claiming and releasing cannot say
                    -- so. Without an expiry that draft could never be sent
                    -- again by anything: the retry, a resubmission and the JMAP
                    -- path would all be refused by bookkeeping belonging to a
                    -- process that no longer exists. Far longer than any send
                    -- takes and far shorter than a person waits before
                    -- complaining.
                    OR send_claimed_at < LOCALTIMESTAMP - INTERVAL \'15 minutes\'
                )',
            $parameters,
            $types,
        );

        return $affected > 0;
    }

    /**
     * Call a send off, and say whether the call arrived in time.
     *
     * The mirror image of claimForSend(), and the reason a cancel can now be
     * reported honestly. `send_claimed_at IS NULL` is what makes it truthful:
     * once the handler holds the message the cancel has lost, even though
     * sent_at may still be null for as long as the SMTP conversation takes.
     * Checking sent_at alone would have answered "cancelled" for the whole
     * duration of the send that was in flight.
     *
     * submission_send_at is cleared in the same statement because a hold that
     * has been called off must stop being reported as `pending` by
     * EmailSubmission/get — see ComposeController::callOffSend().
     *
     * $cancelledAt is the JMAP shape of the same act, and differs on purpose:
     * EmailSubmission/set keeps submission_send_at and records the cancel in
     * submission_cancelled_at instead, because EmailSubmission/get reports the
     * submission as `canceled` from those two and would answer notFound with
     * the release time gone. The guard is the same either way — that is the
     * part that must not differ between the two surfaces.
     */
    public function cancelSend(int $messageId, ?DateTimeImmutable $cancelledAt = null): bool
    {
        if (null !== $cancelledAt) {
            $affected = $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE message
                    SET cancelled = true,
                        submission_cancelled_at = :cancelledAt
                  WHERE id = :id
                    AND send_claimed_at IS NULL
                    AND sent_at IS NULL',
                ['id' => $messageId, 'cancelledAt' => $cancelledAt],
                ['cancelledAt' => Types::DATETIME_IMMUTABLE],
            );

            return $affected > 0;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message
                SET cancelled = true,
                    submission_send_at = NULL
              WHERE id = :id
                AND send_claimed_at IS NULL
                AND sent_at IS NULL',
            ['id' => $messageId],
        );

        return $affected > 0;
    }

    /**
     * Give a claimed message back, because the send did not happen.
     *
     * Without this a failed send would wedge the message forever: the claim
     * would stand, and the messenger retry — plus any later resubmission from
     * the composer or from EmailSubmission/set — would be refused by
     * claimForSend() as "somebody else holds it". A claim describes a handler
     * that is working on the message right now, so a handler that has stopped
     * working on it has to put it down.
     */
    public function releaseSendClaim(int $messageId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message SET send_claimed_at = NULL WHERE id = :id AND sent_at IS NULL',
            ['id' => $messageId],
        );
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
     * One page of Email/query: the ids of an account's messages matching a
     * compiled JMAP filter, in the requested order, from $position on.
     *
     * Raw SQL for the same reason matchingIds() is: the filter arrives as a SQL
     * fragment compiled from the client's request, because Postgres is the only
     * implementation of what a JMAP filter means.
     *
     * The window is applied here rather than in PHP. This used to return every
     * matching row of the account, sorted, for EmailQueryRunner to slice — on
     * every page of every list a client scrolls, a mailbox's worth of rows to
     * hand back 50. With $collapseThreads the spec's `position` counts
     * collapsed rows, so the collapse has to happen before the window: the
     * inner DISTINCT ON keeps each thread's first message in the requested
     * order (a message with no thread is its own group, keyed by its negated
     * id so it cannot meet a real thread id), and the outer query sorts and
     * pages those. The default order is served by
     * idx_message_account_received_at.
     *
     * $orderBySql is interpolated because ORDER BY takes expressions, not bound
     * values. It is safe by construction: EmailQueryRunner builds it from a
     * fixed property→column map and raises unsupportedSort for anything absent
     * from it, so no client string ever reaches this.
     *
     * @return list<string>
     */
    public function findIdsForQuery(
        int $accountId,
        ?CompiledFilter $filter,
        string $orderBySql,
        bool $collapseThreads,
        int $position,
        ?int $limit,
    ): array {
        [$where, $parameters, $types] = $this->queryWhere($accountId, $filter);

        $sql = true === $collapseThreads
            ? sprintf(
                'SELECT m.id FROM message m WHERE m.id IN (
                    SELECT DISTINCT ON (COALESCE(m.thread_id, -m.id)) m.id
                      FROM message m
                     WHERE %s
                     ORDER BY COALESCE(m.thread_id, -m.id), %s
                 ) ORDER BY %s',
                $where,
                $orderBySql,
                $orderBySql,
            )
            : sprintf('SELECT m.id FROM message m WHERE %s ORDER BY %s', $where, $orderBySql);

        $sql .= ' OFFSET :emailQueryOffset';
        $parameters['emailQueryOffset'] = $position;
        $types['emailQueryOffset']      = ParameterType::INTEGER;

        if (null !== $limit) {
            $sql .= ' LIMIT :emailQueryLimit';
            $parameters['emailQueryLimit'] = $limit;
            $types['emailQueryLimit']      = ParameterType::INTEGER;
        }

        $ids = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $types)
            ->fetchFirstColumn();

        return array_map('strval', $ids);
    }

    /**
     * Email/query's `total`: how many rows the whole collapsed or uncollapsed
     * list has, which findIdsForQuery() no longer reads. A COUNT because that
     * is the question, and raw for the filter fragment, as above.
     */
    public function countForQuery(int $accountId, ?CompiledFilter $filter, bool $collapseThreads): int
    {
        [$where, $parameters, $types] = $this->queryWhere($accountId, $filter);

        $count = true === $collapseThreads ? 'COUNT(DISTINCT COALESCE(m.thread_id, -m.id))' : 'COUNT(*)';

        return (int) $this->getEntityManager()
            ->getConnection()
            ->executeQuery(sprintf('SELECT %s FROM message m WHERE %s', $count, $where), $parameters, $types)
            ->fetchOne();
    }

    /**
     * @return array{0: string, 1: array<string,mixed>, 2: array<string,ArrayParameterType|ParameterType|null>}
     */
    private function queryWhere(int $accountId, ?CompiledFilter $filter): array
    {
        $parameters = ['accountId' => $accountId];
        $types      = [];
        $where      = 'm.account_id = :accountId';

        if (null !== $filter) {
            $where     .= ' AND '.$filter->sql;
            $parameters = array_merge($parameters, $filter->parameters);
            $types      = $filter->parameterTypes();
        }

        return [$where, $parameters, $types];
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
     * The raw subject and text part of specific messages, for the fields
     * `ts_headline` declined to mark.
     *
     * The companion to findSearchHeadlines() above, and only ever called after
     * it: SearchHighlighter's fallback is a substring search, so it needs the
     * field as it was written rather than the fragment Postgres returned. Both
     * callers hold rows rather than hydrated bodies: a JMAP SearchSnippet/get
     * has DBAL rows and nothing else, and the web search page's rows carry no
     * bodies either (see App\Service\Mail\ThreadRows).
     *
     * TWO ID LISTS, BECAUSE THE TWO COLUMNS COST DIFFERENT AMOUNTS. A subject
     * is tens of bytes and a text part is thousands, and the field that is
     * normally unmarked is the SUBJECT — most searches match in the body, so on
     * a plain request every row wants its subject checked and not one wants its
     * body. Scoping this by ROW, the shape first sketched when the fallback was
     * deferred, would therefore drag every body along for the sake of the
     * subjects: measured at 500 ids over ~4.5KB bodies, 7.9ms and 2.2MB to
     * deliver 30KB of subjects.
     *
     * So `$ids` is every row that wants either field and `$withBody` is the
     * subset that wants the expensive one. The CASE is not cosmetic — Postgres
     * does not detoast a column it does not evaluate, and the same statement
     * measures 1.5ms with `$withBody` empty against 7.9ms with all 500 in it,
     * scaling linearly in between (4.5ms at half). An empty `$withBody` is fine
     * and is the common case: DBAL expands an empty array parameter to the
     * literal NULL, `id IN (NULL)` is NULL rather than an error, and the CASE
     * falls through.
     *
     * Raw SQL and not DQL for the same reason findSearchHeadlines() is: this is
     * the other half of one statement pair, and a conditional projection over a
     * TOASTed column is a physical-storage concern the ORM has no vocabulary
     * for. Keyed by id rather than returned as a list, because a lookup is the
     * only thing its one caller can do with it.
     *
     * @param list<int> $ids       every message whose subject or body is wanted
     * @param list<int> $withBody  the subset whose body_text is actually wanted
     *
     * @return array<int, array{subject: mixed, body_text: mixed}>
     */
    public function findSearchTexts(int $accountId, array $ids, array $withBody): array
    {
        if (0 === count($ids)) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                m.id,
                m.subject,
                CASE WHEN m.id IN (:withBody) THEN m.body_text END AS body_text
            FROM message m
            WHERE m.account_id = :account
              AND m.id IN (:ids)
            SQL;

        /** @var list<array{id: int|string, subject: mixed, body_text: mixed}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [
                'withBody' => $withBody,
                'account'  => $accountId,
                'ids'      => $ids,
            ],
            [
                'withBody' => ArrayParameterType::INTEGER,
                'ids'      => ArrayParameterType::INTEGER,
            ],
        );

        $texts = [];

        foreach ($rows as $row) {
            $texts[(int) $row['id']] = ['subject' => $row['subject'], 'body_text' => $row['body_text']];
        }

        return $texts;
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
     * $labelIds narrows the aggregate to those labels, for a Mailbox/get that
     * named its ids; null counts every label of the account.
     *
     * @param list<int>|null $labelIds
     *
     * @return array<int,array{total:int,unread:int}> label id => counts
     */
    public function countEmailsPerLabelForAccount(int $accountId, ?array $labelIds = null): array
    {
        return $this->labelCounts($accountId, $labelIds, <<<'SQL'
            SELECT ml.label_id,
                   COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
              %s
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
     * @param list<int>|null $labelIds as countEmailsPerLabelForAccount()
     *
     * @return array<int,array{total:int,unread:int}> label id => counts
     */
    public function countThreadsPerLabelForAccount(int $accountId, ?array $labelIds = null): array
    {
        return $this->labelCounts($accountId, $labelIds, <<<'SQL'
            SELECT ml.label_id,
                   COUNT(DISTINCT m.thread_id) AS total,
                   COUNT(DISTINCT m.thread_id) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
              AND m.thread_id IS NOT NULL
              %s
            GROUP BY ml.label_id
            SQL);
    }

    /**
     * @param list<int>|null $labelIds
     *
     * @return array<int,array{total:int,unread:int}>
     */
    private function labelCounts(int $accountId, ?array $labelIds, string $sql): array
    {
        if ([] === $labelIds) {
            return [];
        }

        $parameters = ['accountId' => $accountId];
        $types      = [];
        $narrow     = '';

        if (null !== $labelIds) {
            $narrow               = 'AND ml.label_id IN (:labelIds)';
            $parameters['labelIds'] = $labelIds;
            $types['labelIds']      = ArrayParameterType::INTEGER;
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(sprintf($sql, $narrow), $parameters, $types);
        $counts = [];

        foreach ($rows as $row) {
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
    /**
     * One page of a user's message ids, ascending, for a walk that has to
     * survive being interrupted.
     *
     * Ascending id rather than date because it is the one ordering nothing can
     * change underneath a walk that takes hours: mail arriving during it gets a
     * higher id and is met by the pass still coming, and mail deleted during it
     * is simply absent. A date cursor would have to cope with both, and with
     * two messages sharing a timestamp.
     *
     * Scoped through the account, which is where ownership lives — a message
     * belongs to a user only by way of one.
     *
     * @return list<int>
     */
    public function idsForUserAfter(int $userId, ?int $afterId, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT m.id
                  FROM message m
                  JOIN account a ON a.id = m.account_id
                 WHERE a.usr_id = :userId
                   AND (:afterId::int IS NULL OR m.id > :afterId::int)
                 ORDER BY m.id ASC
                 LIMIT :limit
            SQL,
            ['userId' => $userId, 'afterId' => $afterId, 'limit' => $limit],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * The newest of one user's messages that have no vector from THIS model.
     *
     * What the two catch-up triggers walk — the nightly sweep and the batch
     * queued in the warm minute after somebody searched. See
     * App\Service\Ai\EmbeddingCatchUp, which is the only caller.
     *
     * NEWEST FIRST, which is the opposite of idsForUserAfter() above and for a
     * reason rather than a preference. That one is a WALK: it has to cover a
     * whole mailbox exactly once and never lose its place, so it goes forwards
     * by id and remembers where it stopped. This is a CATCH-UP with a budget:
     * it will only ever do a few messages, so the few it does should be the
     * mail somebody might actually search for tomorrow morning. A shared
     * ordering would make one of the two wrong.
     *
     * THE MODEL IS PART OF "INDEXED". Vectors from two embedding models are not
     * comparable — different space, different width, and the shipped distance
     * function compares whatever overlaps rather than refusing — so after
     * somebody changes the model in settings every stored row is dead weight
     * and every message is outstanding again. Matching the model here is what
     * makes that true rather than leaving a mailbox that reports itself indexed
     * and searches like it is not.
     *
     * AND DELIBERATELY NOT THE WIDTH. EmbeddingStore::
     * coverageDetailFor() matches on both, and this deliberately does not, so
     * here is the trap it avoids. What consumes these ids is
     * EmbedMessagesHandler, which skips whatever EmbeddingStore::
     * alreadyStored() reports — and that asks about the MODEL alone. A finder
     * that tested the width too would, on the one installation where the two
     * disagree (a model upgraded in place and now answering at a different
     * width), hand over a full budget of ids that the handler then drops as
     * already done: a sweep that runs every night, reports itself busy, and
     * indexes nothing, forever. The width mismatch is a real problem and it
     * already has an owner — coverageDetailFor()'s third number, which tells
     * the person their mailbox needs re-indexing rather than quietly trying.
     *
     * NOT EXISTS rather than a LEFT JOIN with an IS NULL: it is the same
     * anti-join to the planner and it cannot accidentally multiply rows if the
     * embedding table ever stops being keyed one-to-one on the message.
     *
     * THE JOIN GOES THROUGH THE THREAD, which is what coverageDetailFor()
     * counts through. The two have to agree or the notice under somebody's
     * search box says "4,120 of 4,125 indexed" forever while a sweep that can
     * see five more messages than the counter can keeps finding nothing to do.
     *
     * WHAT IT COSTS WHEN THERE IS NOTHING TO DO. The LIMIT can only stop the
     * scan early when there IS outstanding mail; a fully indexed mailbox is
     * walked to the end. That is bounded by the mailbox and it is strictly
     * cheaper than the coverage count the search page already pays for on the
     * same request — but it is the reason both callers are rate-limited rather
     * than run per search.
     *
     * @return list<int>
     */
    public function unembeddedIdsForUser(int $userId, string $model, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT m.id
                  FROM message m
                  JOIN message_thread t ON t.id = m.thread_id
                  JOIN account a ON a.id = t.account_id
                 WHERE a.usr_id = :userId
                   AND NOT EXISTS (
                       SELECT 1
                         FROM message_embedding e
                        WHERE e.message_id = m.id
                          AND e.model = :model
                   )
                 ORDER BY m.id DESC
                 LIMIT :limit
            SQL,
            [
                'userId' => $userId,
                'model'  => $model,
                'limit'  => $limit,
            ],
            [
                'userId' => ParameterType::INTEGER,
                'limit'  => ParameterType::INTEGER,
            ],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * The newest message ids across one person's active accounts.
     *
     * Ids and not entities: the only caller hands them to a Messenger envelope
     * a batch at a time, and hydrating a few hundred messages to read their
     * primary keys would be a waste of an identity map.
     *
     * Ordered by arrival with the id as the tiebreaker, so "the last 200" is a
     * stable set rather than whatever the planner felt like returning — mail
     * with no receivedAt (this account's own sent copies) sorts last rather
     * than first, which is what NULLS LAST buys.
     *
     * @return list<int>
     */
    public function findRecentIdsForUser(int $userId, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
            SELECT m.id
              FROM message m
              JOIN account a ON a.id = m.account_id
             WHERE a.usr_id = :userId
               AND a.is_active = true
             ORDER BY m.received_at DESC NULLS LAST, m.id DESC
             LIMIT :limit
            SQL,
            ['userId' => $userId, 'limit' => $limit],
            ['userId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(intval(...), $ids);
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
     * Messages whose body webklex filed as an attachment, oldest id first.
     *
     * The set MisfiledBodyDetector now keeps out of the database, found after
     * the fact. Two conditions, and each carries half the confidence:
     *
     * `multipart/*` IS IN THE LIST, and it is what the real reports turned out
     * to be. webklex hands over a whole `multipart/related` when it holds a
     * single part, so the row is not a text body at all but the MIME block
     * around one — see MisfiledBodyUnpacker. Selecting only text/plain and
     * text/html found nothing on the installation that reported this.
     *
     * A FILENAME OF EXACTLY EIGHT CHARACTERS, because that is not a filename.
     * Webklex fills an absent one with `hash("crc32c", …)` of the part, and
     * eight is what that hash measures. The hex-ness is checked in PHP rather
     * than here — a LIKE pattern for it is eight repetitions of a character
     * class and unreadable, and this predicate is only narrowing the walk.
     *
     * AN EMPTY BODY OF THE PART'S OWN TYPE, which is what makes the first
     * condition safe to act on. A nameless text part next to a body that
     * parsed fine is something else and is left alone; a nameless text part
     * next to nothing at all is the body, and the message currently renders
     * blank either way.
     *
     * EXISTS rather than a join, and that is not a preference. A
     * multipart/alternative can have both halves misfiled, so a join returns
     * the message once per matching part — and the obvious fix, SELECT
     * DISTINCT, cannot run at all here: Message carries json columns and
     * Postgres has no equality operator for json, so the query dies with
     * "could not identify an equality operator for type json". EXISTS asks the
     * question without multiplying the rows, so there is nothing to
     * de-duplicate.
     *
     * @return list<Message>
     */
    public function findWithMisfiledBodyPart(int $afterId, int $limit): array
    {
        return $this->misfiledBodyPartQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithMisfiledBodyPart(): int
    {
        return (int) $this->misfiledBodyPartQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function misfiledBodyPartQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere(
                'EXISTS ('
                . ' SELECT p.id FROM ' . MessagePart::class . ' p'
                . ' WHERE p.message = m'
                . " AND (p.contentType IN (:bodyTypes) OR p.contentType LIKE 'multipart/%')"
                . ' AND LENGTH(p.filename) = 8'
                . " AND ((p.contentType = 'text/html' AND (m.bodyHtml IS NULL OR m.bodyHtml = ''))"
                . "   OR (p.contentType = 'text/plain' AND (m.bodyText IS NULL OR m.bodyText = ''))"
                // A container can yield both bodies at once, so it only counts
                // when there is nothing to overwrite in either.
                . "   OR (p.contentType LIKE 'multipart/%'"
                . "       AND (m.bodyHtml IS NULL OR m.bodyHtml = '')"
                . "       AND (m.bodyText IS NULL OR m.bodyText = '')))"
                . ')'
            )
            ->setParameter('afterId', $afterId)
            ->setParameter('bodyTypes', ['text/plain', 'text/html']);
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
     * The UIDs above $aboveUid this mailbox has already stored.
     *
     * QueryBuilder for the projection: the syncer diffs a set of integers
     * against what the server offers, and hydrating a Message per UID to read
     * one column would make a routine poll cost a mailbox load.
     *
     * Bounded below because the syncer only ever asks the server for UIDs
     * above its high-water mark: loading every UID a large folder has ever
     * stored, on every poll, to check the handful above it was the cost.
     */
    public function findSyncedUids(Mailbox $mailbox, int $aboveUid = 0): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.imapUid')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
            ->andWhere('m.imapUid > :aboveUid')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('aboveUid', $aboveUid)
            ->getQuery()
            ->getSingleColumnResult();
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
     * @return array<int,array{id:int,flags:list<string>,seen:bool,flagged:bool,pending:bool}>
     *         imapUid => its current flag state
     */
    public function findFlagStateByUid(Mailbox $mailbox): array
    {
        /** @var list<array{id:int,imapUid:int,flags:list<string>|null,seenAt:?\DateTimeInterface,starredAt:?\DateTimeInterface,flagsTouchedAt:?\DateTimeInterface}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'm.imapUid', 'm.flags', 'm.seenAt', 'm.starredAt', 'm.flagsTouchedAt')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
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
                'pending' => null !== $row['flagsTouchedAt'],
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
        return $this->findOneBy(['mailbox' => $mailbox, 'imapUid' => $uid]);
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
     * The one row for a Gmail id among the owner's accounts, for the batch
     * handler's "already stored?" check.
     *
     * Scoped through the user and not the account, for the reason given on
     * findByGmailIdsForUser(): a message fetched by one Gmail account may be
     * attributed to a sibling. It used to be a bare findOneBy(['gmailId']),
     * which searched every user on the install — a gmailId is only unique per
     * account, so another user's row could be found and enriched with this
     * user's labels. QueryBuilder because the owner is behind an association.
     */
    public function findOneByGmailIdForUser(User $user, string $gmailId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId = :gmailId')
            ->setParameter('usr', $user)
            ->setParameter('gmailId', $gmailId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
            )
            ->where('m.thread IN (:threads)')
            ->setParameter('threads', $threadIds)
            ->addOrderBy('m.receivedAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * The two body columns MessageSnippet reads, for specific messages only —
     * one per list row, the row's latest.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{bodyHtmlSafe: ?string, bodyText: ?string}>
     */
    public function findSnippetBodies(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string, bodyHtmlSafe: ?string, bodyText: ?string}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'm.bodyHtmlSafe', 'm.bodyText')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $bodies = [];

        foreach ($rows as $row) {
            $bodies[(int) $row['id']] = ['bodyHtmlSafe' => $row['bodyHtmlSafe'], 'bodyText' => $row['bodyText']];
        }

        return $bodies;
    }
}
