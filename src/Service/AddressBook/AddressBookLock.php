<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Entity\AddressBook\AddressBook;
use Doctrine\DBAL\Connection;

/**
 * One run at a time per address book, across workers.
 *
 * Two queues touch a book: the push after an edit on `export`, the sweep and
 * the first sync on `ingest` (see messenger.yaml). Each has its own worker, so
 * the two can run at the same moment, and both begin by sending what is
 * pending. For CardDAV that was harmless — a create is a PUT with
 * If-None-Match: * on an href derived from the UID, so the second one is
 * refused — but Google's createContact and Graph's POST have no such guard,
 * and two runs at once would create the contact twice.
 *
 * A Postgres session advisory lock, keyed on the book, held for the run and
 * released in finally. Blocking rather than skipping: a push that arrives
 * while the sweep is mid-pull waits a few seconds and then sends the edit,
 * instead of leaving it for the next sweep.
 */
final readonly class AddressBookLock
{
    /** The first key of the pair, so these locks cannot collide with another feature's. */
    private const int NAMESPACE = 0x41424B00;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function exclusively(AddressBook $book, callable $work): mixed
    {
        $key = (int) $book->id;

        $this->connection->executeQuery('SELECT pg_advisory_lock(?, ?)', [self::NAMESPACE, $key]);

        try {
            return $work();
        } finally {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?, ?)', [self::NAMESPACE, $key]);
        }
    }
}
