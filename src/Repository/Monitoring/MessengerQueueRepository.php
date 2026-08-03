<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads over `messenger_messages`, the doctrine transport's own table.
 *
 * Not a ServiceEntityRepository: the table belongs to Symfony Messenger, which
 * creates and migrates it itself, and mapping an entity onto it would give
 * plMail a second opinion about a schema it does not own. It is still a table
 * this application queries, and the house rule is that queries live in a
 * repository — the same call DataResetRepository makes about TRUNCATE.
 *
 * Read-only on purpose. Acting on messages (retry, delete) goes through the
 * transport's receiver so envelopes keep their stamps; only counting them is
 * cheaper as SQL than as a full deserialize of every pending envelope.
 */
final readonly class MessengerQueueRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Depth, in-flight count and oldest timestamps per queue.
     *
     * "Pending" is `delivered_at IS NULL`, which is the transport's own
     * definition of a message no worker has taken yet; the complement is a
     * message some worker is holding right now. Grouped in SQL rather than
     * counted in PHP: the answer is a handful of integers per queue, and
     * hydrating a backlog to count it is what the backlog would punish.
     *
     * Aggregate FILTER is Postgres-only, which this application already is.
     * Written by hand because Doctrine's API has no conditional aggregate, and
     * the alternative is four round trips for four numbers.
     *
     * @return list<array{queue_name: string, pending: int|string, running: int|string, oldest: string|null, oldest_created: string|null, oldest_delivered: string|null}>
     */
    public function statsByQueue(): array
    {
        /** @var list<array{queue_name: string, pending: int|string, running: int|string, oldest: string|null, oldest_created: string|null, oldest_delivered: string|null}> */
        return $this->connection->fetchAllAssociative(
            'SELECT queue_name,
                    COUNT(*) FILTER (WHERE delivered_at IS NULL)     AS pending,
                    COUNT(*) FILTER (WHERE delivered_at IS NOT NULL) AS running,
                    MIN(available_at) FILTER (WHERE delivered_at IS NULL) AS oldest,
                    MIN(created_at)   FILTER (WHERE delivered_at IS NULL) AS oldest_created,
                    MIN(delivered_at) FILTER (WHERE delivered_at IS NOT NULL) AS oldest_delivered
             FROM messenger_messages
             GROUP BY queue_name
             ORDER BY queue_name',
        );
    }

    /**
     * The messages a worker is holding right now.
     *
     * Never paginated: there is one per worker process, so the list is as long
     * as the deployment has consumers and no longer.
     *
     * @return list<array{id: int|string, queue_name: string, body: string, headers: string, created_at: string, available_at: string, delivered_at: string|null}>
     */
    public function runningMessages(int $limit = 20, ?string $filter = null): array
    {
        return $this->fetchMessages('delivered_at IS NOT NULL', 'delivered_at, id', $limit, 0, $filter);
    }

    /**
     * The backlog: everything no worker has taken yet, oldest first.
     *
     * @return list<array{id: int|string, queue_name: string, body: string, headers: string, created_at: string, available_at: string, delivered_at: string|null}>
     */
    public function waitingMessages(int $limit = 25, int $offset = 0, ?string $filter = null): array
    {
        return $this->fetchMessages('delivered_at IS NULL', 'available_at, id', $limit, $offset, $filter);
    }

    /**
     * How long the backlog is under the current filter — the number the panel
     * counts down as pages are loaded, so "showing 25 of 4 100" is honest
     * about what has not been fetched.
     */
    public function countWaiting(?string $filter = null): int
    {
        [$condition, $params, $types] = $this->filterClause($filter);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages
             WHERE queue_name <> :failed AND delivered_at IS NULL' . $condition,
            ['failed' => 'failed'] + $params,
            ['failed' => ParameterType::STRING] + $types,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The failure queue is left out of every read here: it has its own panel,
     * with its own actions, and showing the same rows twice would only invite
     * acting on them in the place that cannot.
     *
     * Bodies come along because the message class and its payload are only
     * inside the serialised envelope — see QueueMonitor, which decodes them.
     *
     * @param string $state    SQL condition selecting held or waiting rows
     * @param string $ordering trailing ORDER BY, without the keyword
     *
     * @return list<array{id: int|string, queue_name: string, body: string, headers: string, created_at: string, available_at: string, delivered_at: string|null}>
     */
    private function fetchMessages(
        string $state,
        string $ordering,
        int $limit,
        int $offset,
        ?string $filter,
    ): array {
        [$condition, $params, $types] = $this->filterClause($filter);

        /** @var list<array{id: int|string, queue_name: string, body: string, headers: string, created_at: string, available_at: string, delivered_at: string|null}> */
        return $this->connection->fetchAllAssociative(
            "SELECT id, queue_name, body, headers, created_at, available_at, delivered_at
             FROM messenger_messages
             WHERE queue_name <> :failed AND {$state}{$condition}
             ORDER BY {$ordering}
             LIMIT :limit OFFSET :offset",
            ['failed' => 'failed', 'limit' => $limit, 'offset' => $offset] + $params,
            // LIMIT and OFFSET are typed explicitly: bound as strings,
            // Postgres refuses them.
            [
                'failed' => ParameterType::STRING,
                'limit'  => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ] + $types,
        );
    }

    /**
     * Free-text filter over the whole queue, not just the rows already on
     * screen — which is the point of it, so it has to be SQL.
     *
     * `body` is the serialised envelope, and PHP serialisation writes class
     * names and scalar payload values as plain text inside it: matching the
     * blob is what makes `SyncAccountMessage` or an account id findable
     * without deserialising several thousand envelopes per keystroke. It is a
     * substring match on a serialised structure, so it can in principle match
     * a property name or a stamp — for a search box over a queue, that is a
     * fair trade for answering in one index-free scan of a table that is
     * small by construction.
     *
     * @return array{string, array<string, string>, array<string, ParameterType>}
     */
    private function filterClause(?string $filter): array
    {
        $filter = null === $filter ? '' : trim($filter);

        if ('' === $filter) {
            return ['', [], []];
        }

        return [
            ' AND (queue_name ILIKE :filter OR body ILIKE :filter)',
            ['filter' => '%' . $filter . '%'],
            ['filter' => ParameterType::STRING],
        ];
    }
}
