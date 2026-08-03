<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use Doctrine\DBAL\Connection;

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
     * Pending depth and oldest waiting message per queue.
     *
     * "Pending" is `delivered_at IS NULL`, which is the transport's own
     * definition of a message no worker has taken yet. Grouped in SQL rather
     * than counted in PHP: the answer is two integers per queue, and hydrating
     * a backlog to count it is what the backlog would punish.
     *
     * @return list<array{queue_name: string, pending: int|string, oldest: string|null}>
     */
    public function pendingByQueue(): array
    {
        /** @var list<array{queue_name: string, pending: int|string, oldest: string|null}> */
        return $this->connection->fetchAllAssociative(
            'SELECT queue_name, COUNT(*) AS pending, MIN(available_at) AS oldest
             FROM messenger_messages
             WHERE delivered_at IS NULL
             GROUP BY queue_name
             ORDER BY queue_name',
        );
    }
}
