<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\Query\CompiledFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Messages as JMAP asks for them: Email/query, Email/get, SearchSnippet/get, and the
 * JMAP Email ids an account's rows carry.
 */
class MessageJmapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
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
     * The body is the one search_vector indexed: body_html_text where it is
     * set, the text part otherwise (#477). Headlining body_text alone would
     * find nothing to mark in an HTML-only message the search just matched.
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
                ts_headline('english', coalesce(m.body_html_text, m.body_text, ''),
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
     * "body_text" in the result is the body findSearchHeadlines() headlined,
     * body_html_text where there is one, so that the fallback looks for the
     * hit in the same words.
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
                CASE WHEN m.id IN (:withBody) THEN coalesce(m.body_html_text, m.body_text) END AS body_text
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
     * Drop every JMAP Email id an account's rows carry, keeping the rows.
     *
     * The JMAP half of unlocateAll(), for an account that stops reading over
     * JMAP: the ids name nothing to the protocol it switches to, and a row
     * that kept one would go on being patched and destroyed by JMAP jobs
     * still on the queue. Recognised again by Message-ID if it ever switches
     * back — see JmapEmailSyncer.
     */
    public function forgetJmapIds(Account $account): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(Message::class, 'm')
            ->set('m.jmapId', ':nothing')
            ->where('m.account = :account')
            ->andWhere('m.jmapId IS NOT NULL')
            ->setParameter('nothing', null)
            ->setParameter('account', $account)
            ->getQuery()
            ->execute();
    }

    /**
     * The rows behind a set of JMAP Email ids on one account.
     *
     * Scoped to the account and not the user, unlike the Gmail lookups: a
     * JMAP Email id is the server's name for a message in one mailbox store,
     * and nothing attributes a JMAP message to a sibling account.
     *
     * @param list<string> $jmapIds
     *
     * @return list<Message>
     */
    public function findByJmapIdsForAccount(Account $account, array $jmapIds): array
    {
        if ([] === $jmapIds) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.account = :account')
            ->andWhere('m.jmapId IN (:jmapIds)')
            ->setParameter('account', $account)
            ->setParameter('jmapIds', $jmapIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Which of these JMAP Email ids the account already has, as ids only:
     * what a page of a listing asks before it fetches anything.
     *
     * @param list<string> $jmapIds
     *
     * @return list<string>
     */
    public function findStoredJmapIdsForAccount(Account $account, array $jmapIds): array
    {
        if ([] === $jmapIds) {
            return [];
        }

        return array_map(strval(...), $this->createQueryBuilder('m')
            ->select('m.jmapId')
            ->where('m.account = :account')
            ->andWhere('m.jmapId IN (:jmapIds)')
            ->setParameter('account', $account)
            ->setParameter('jmapIds', $jmapIds)
            ->getQuery()
            ->getSingleColumnResult());
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
}
