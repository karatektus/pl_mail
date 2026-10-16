<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Service\AddressBook\AddressBookSync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one address book's sync — thin, like SyncCalendarHandler, and with the
 * same split.
 *
 * A permanent failure (a revoked password, a deleted book) is recorded on the
 * book by the sync service and swallowed here: rethrowing it would log a
 * CRITICAL and file a dead envelope every fifteen minutes for something only a
 * person can fix, and the settings list already says what is wrong. A
 * transient failure escapes, because there the transport's retry ladder is the
 * point.
 */
#[AsMessageHandler]
final readonly class SyncAddressBookHandler
{
    public function __construct(
        private AddressBookRepository $books,
        private AddressBookSync       $sync,
        private LoggerInterface       $logger,
    ) {
    }

    public function __invoke(SyncAddressBookMessage $message): void
    {
        $book = $this->books->find($message->addressBookId);

        if (null === $book) {
            // Unsubscribed between the dispatch and the run. Normal.
            return;
        }

        if (false === $book->integration->isActive) {
            return;
        }

        $error = $book->lastSyncError;

        try {
            $touched = $this->sync->sync($book);
        } catch (CalendarSyncPermanentException $e) {
            if ($error !== $e->getMessage()) {
                $this->logger->notice('AddressBookSync: stopped until this is fixed', [
                    'addressBookId' => $book->id,
                    'error'         => $e->getMessage(),
                ]);
            }

            return;
        } catch (CalendarSyncException $e) {
            if ($error !== $e->getMessage()) {
                $this->logger->error('AddressBookSync: sync failed', [
                    'addressBookId' => $book->id,
                    'error'         => $e->getMessage(),
                    'exception'     => $e,
                ]);
            }

            throw $e;
        }

        if (0 < $touched) {
            $this->logger->info('AddressBookSync: address book synced', [
                'addressBookId' => $book->id,
                'cards'         => $touched,
            ]);
        }
    }
}
