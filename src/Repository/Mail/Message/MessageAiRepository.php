<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Helper\CalendarAttachment;
use App\Entity\Mail\Message;
use App\Service\Graph\GraphMessageBuilder;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Messages the assistant works on: extraction candidates, mail without a vector,
 * unclassified mail, and mail held for the assistant.
 */
class MessageAiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Messages that could plausibly carry an event.
     *
     * A mailbox is mostly newsletters, and parsing every one to find the few
     * per cent that are bookings is work nobody gets back. Three signals, one
     * per extractor: a calendar part (an invite, on IMAP or Gmail — see CalendarAttachment), the
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
     * combined with a compiled filter, as MessageFilterRepository::matchingIds does.
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
                    AND (
                          LOWER(p.content_type) IN (:extCalendarTypes)
                       OR LOWER(p.filename) LIKE :extCalendarName
                    )
              )
           OR jsonb_exists(m.headers::jsonb, :extMeetingHeader)
           OR m.body_html LIKE :extJsonLd
        )
        SQL;

    /**
     * Raw DBAL for the reasons EXTRACTION_CANDIDATE_WHERE gives above.
     *
     * @param DateTimeImmutable|null $since only mail received at or after this; null for all of it
     */
    public function countExtractionCandidates(?DateTimeImmutable $since = null): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM message m WHERE ' . self::EXTRACTION_CANDIDATE_WHERE . $this->candidateSince($since),
            $this->candidateParameters(0, $since),
            $this->candidateTypes(),
        );
    }

    /**
     * @param DateTimeImmutable|null $since only mail received at or after this; null for all of it
     *
     * @return list<Message>
     */
    public function extractionCandidates(int $afterId, int $limit, ?DateTimeImmutable $since = null): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT m.id FROM message m WHERE ' . self::EXTRACTION_CANDIDATE_WHERE . $this->candidateSince($since)
            . ' ORDER BY m.id ASC LIMIT ' . max(1, $limit),
            $this->candidateParameters($afterId, $since),
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
    private function candidateParameters(int $afterId, ?DateTimeImmutable $since = null): array
    {
        return (null === $since ? [] : ['extSince' => $since]) + [
            'extAfterId'       => $afterId,
            'extCalendarTypes' => CalendarAttachment::CONTENT_TYPES,
            'extCalendarName'  => '%' . CalendarAttachment::EXTENSION,
            'extMeetingHeader' => GraphMessageBuilder::MEETING_TYPE_HEADER,
            'extJsonLd'        => '%application/ld+json%',
        ];
    }

    /**
     * The clause that narrows candidates to recent mail, or nothing.
     *
     * Appended rather than written into the constant as "IS NULL OR": Postgres
     * cannot infer the type of a parameter that is only ever compared to NULL,
     * and a clause that is absent needs no parameter at all.
     */
    private function candidateSince(?DateTimeImmutable $since): string
    {
        return null === $since ? '' : ' AND m.received_at >= :extSince';
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateTypes(): array
    {
        return [
            'extAfterId'       => ParameterType::INTEGER,
            'extCalendarTypes' => ArrayParameterType::STRING,
            'extCalendarName'  => ParameterType::STRING,
            'extSince'         => Types::DATETIME_IMMUTABLE,
            'extMeetingHeader' => ParameterType::STRING,
            'extJsonLd'        => ParameterType::STRING,
        ];
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
               AND a.mail_enabled = true
             ORDER BY m.received_at DESC NULLS LAST, m.id DESC
             LIMIT :limit
            SQL,
            ['userId' => $userId, 'limit' => $limit],
            ['userId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(intval(...), $ids);
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
     * The newest mail of one person's that the assistant has never been asked
     * about — what ClassificationCatchUp works through after an import.
     *
     * NEWEST FIRST, the opposite of a backfill's walk and for the reason
     * EmbeddingCatchUp gives: this is a bounded handful per run, and the mail
     * worth spending it on is the mail somebody is most likely to scroll to.
     * A mailbox is classified backwards from today, so stopping at any point
     * leaves the useful end done.
     *
     * `ai_categorised_at IS NULL` is "never asked", not "no answer" — the
     * stamp is written even when the model said nothing usable — so a message
     * the model cannot answer is offered once and not on every run for ever.
     * A host that could not be reached leaves it unstamped, so it is offered
     * again here once the host is back.
     *
     * $since is the age limit from Admin → AI (#208), applied to the date the
     * way AiSettings::isTooOldToSortInBulk() applies it — receivedAt, else
     * sentAt, and a message with neither is not too old — so the query and
     * the handler that checks again cannot disagree about a message. Without
     * it the walk would reach past the limit once the recent mail was done,
     * and queue envelopes the handler would only throw away.
     *
     * @param DateTimeImmutable|null $since nothing older than this; null for any age
     *
     * @return list<int>
     */
    public function unclassifiedIdsForUser(int $userId, int $limit, ?DateTimeImmutable $since = null): array
    {
        $sql = <<<'SQL'
            SELECT m.id
              FROM message m
              JOIN account a ON a.id = m.account_id
             WHERE a.usr_id = :userId
               AND a.mail_enabled = true
               AND m.ai_categorised_at IS NULL
            SQL;

        $parameters = ['userId' => $userId, 'limit' => $limit];
        $types      = ['userId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER];

        // Appended rather than written in as "IS NULL OR", for the reason
        // candidateSince() gives: Postgres cannot type a parameter that is only
        // ever compared with NULL.
        if (null !== $since) {
            $sql .= "\n" . <<<'SQL'
                   AND (m.received_at >= :since
                        OR (m.received_at IS NULL AND (m.sent_at IS NULL OR m.sent_at >= :since)))
                SQL;

            $parameters['since'] = $since;
            $types['since']      = Types::DATETIME_IMMUTABLE;
        }

        $sql .= "\n" . <<<'SQL'
             ORDER BY m.received_at DESC NULLS LAST, m.id DESC
             LIMIT :limit
            SQL;

        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, $parameters, $types);

        return array_map(intval(...), $ids);
    }

    /**
     * Mail still held for the assistant that was held before $before.
     *
     * For the sweep that is the last line of the promise that holding is
     * bounded — see ReleaseHeldMailCommand. Limited, because the caller
     * hydrates what this returns and the case where there is a great deal of
     * it is the case where something is already wrong.
     *
     * @return list<Message>
     */
    public function findHeldBefore(DateTimeImmutable $before, int $limit = 200): array
    {
        /** @var list<Message> */
        return $this->createQueryBuilder('m')
            ->where('m.categoryHeldAt IS NOT NULL')
            ->andWhere('m.categoryReleasedAt IS NULL')
            ->andWhere('m.categoryHeldAt < :before')
            ->setParameter('before', $before)
            ->orderBy('m.categoryHeldAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * How long holding mail for the assistant has actually delayed it.
     *
     * The number Admin → AI puts beside the switch, so that "is the wait worth
     * it" is answered by this installation's own host rather than by a guess.
     *
     * `timedOut` is a message shown before the model had answered — no
     * ai_categorised_at at all, or one later than the release. It went to the
     * rules' tab first and may have moved when the answer came. A high share
     * of those means the wait is being paid and the jump is happening anyway.
     *
     * percentile_cont is Postgres-only, which this application already is.
     * Both come back null over an empty window, and are passed on as null —
     * a median of zero would read as "instant" where the truth is "no data".
     *
     * @return array{held: int, timedOut: int, median: float|null, p95: float|null, max: float|null}
     */
    public function holdDelayStats(DateTimeImmutable $since): array
    {
        /** @var array{held: int|string, timed_out: int|string, median: float|string|null, p95: float|string|null, max: float|string|null}|false $row */
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS held,
                   COUNT(*) FILTER (WHERE m.ai_categorised_at IS NULL OR m.ai_categorised_at > m.category_released_at) AS timed_out,
                   percentile_cont(0.5)  WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (m.category_released_at - m.category_held_at))) AS median,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (m.category_released_at - m.category_held_at))) AS p95,
                   MAX(EXTRACT(EPOCH FROM (m.category_released_at - m.category_held_at))) AS max
              FROM message m
             WHERE m.category_held_at >= :since
               AND m.category_released_at IS NOT NULL
            SQL,
            ['since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        if (false === $row) {
            return ['held' => 0, 'timedOut' => 0, 'median' => null, 'p95' => null, 'max' => null];
        }

        return [
            'held'     => (int) $row['held'],
            'timedOut' => (int) $row['timed_out'],
            'median'   => null === $row['median'] ? null : (float) $row['median'],
            'p95'      => null === $row['p95'] ? null : (float) $row['p95'],
            'max'      => null === $row['max'] ? null : (float) $row['max'],
        ];
    }

    /**
     * The held messages themselves, newest first — what the summary above is a
     * summary of.
     *
     * The summary says "typically six seconds, one took thirteen". This is the
     * thirteen: which message, and where its time went. See
     * Message::$aiQueueMs for the three parts.
     *
     * NO SUBJECT AND NO SENDER, and that is a rule of the admin panel rather
     * than an economy here: an administrator can manage every mailbox on the
     * installation and read none of them. The account is named because the
     * panel already lists accounts; what was in the mail is nobody's business
     * but its owner's.
     *
     * `answeredAfter` is null for a message the model was never heard from
     * about. `stillHeld` is a message being waited on at this moment.
     *
     * @return list<array{id: int, account: string, heldAt: DateTimeImmutable, shownAfter: float|null,
     *     answeredAfter: float|null, stillHeld: bool, shownFirst: bool, queueMs: int|null, callMs: int|null,
     *     loadMs: int|null, filed: string|null, verdict: string|null}>
     */
    public function holdDetails(DateTimeImmutable $since, int $limit = 50): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT m.id,
                   a.email AS account,
                   m.category_held_at AS held_at,
                   EXTRACT(EPOCH FROM (m.category_released_at - m.category_held_at)) AS shown_after,
                   EXTRACT(EPOCH FROM (m.ai_categorised_at - m.category_held_at))    AS answered_after,
                   m.category_released_at IS NULL AS still_held,
                   m.category_released_at IS NOT NULL
                       AND (m.ai_categorised_at IS NULL OR m.ai_categorised_at > m.category_released_at) AS shown_first,
                   m.ai_queue_ms, m.ai_call_ms, m.ai_load_ms,
                   m.category, m.ai_category
              FROM message m
              JOIN account a ON a.id = m.account_id
             WHERE m.category_held_at >= :since
             ORDER BY m.category_held_at DESC, m.id DESC
             LIMIT :limit
            SQL,
            ['since' => $since, 'limit' => $limit],
            ['since' => Types::DATETIME_IMMUTABLE, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'account'       => (string) $row['account'],
            'heldAt'        => new DateTimeImmutable((string) $row['held_at']),
            'shownAfter'    => null === $row['shown_after'] ? null : (float) $row['shown_after'],
            'answeredAfter' => null === $row['answered_after'] ? null : (float) $row['answered_after'],
            'stillHeld'     => true === (bool) $row['still_held'],
            'shownFirst'    => true === (bool) $row['shown_first'],
            'queueMs'       => null === $row['ai_queue_ms'] ? null : (int) $row['ai_queue_ms'],
            'callMs'        => null === $row['ai_call_ms'] ? null : (int) $row['ai_call_ms'],
            'loadMs'        => null === $row['ai_load_ms'] ? null : (int) $row['ai_load_ms'],
            // Where it ended up, which after a verdict is the assistant's answer and
            // not the rules' — the rules' own is not kept once it has been replaced.
            'filed'         => null === $row['category'] ? null : (string) $row['category'],
            'verdict'       => null === $row['ai_category'] ? null : (string) $row['ai_category'],
        ], $rows);
    }
}
