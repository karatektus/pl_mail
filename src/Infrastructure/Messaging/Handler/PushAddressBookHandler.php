<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Infrastructure\Messaging\Message\PushAddressBookMessage;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Service\AddressBook\AddressBookSync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends one address book's pending edits — SyncAddressBookHandler's split, for
 * the push alone.
 *
 * A permanent failure is recorded on the book and swallowed; a transient one
 * escapes for the export transport's retries. When those run out nothing is
 * lost: the contact is still pending, and the next sweep pushes it before it
 * reads anything.
 *
 * One case wants the server read at once. A conflict, or a card the server
 * refused, lets the server win (AddressBookSync::letTheServerWin()): the edit
 * is dropped, but the contact still shows it until a pull brings the server's
 * copy. A full sync used to do that in the same run; here it is queued,
 * whenever the push has thrown away the book's position to force a full read.
 */
#[AsMessageHandler]
final readonly class PushAddressBookHandler
{
    public function __construct(
        private AddressBookRepository $books,
        private AddressBookSync       $sync,
        private MessageBusInterface   $bus,
        private LoggerInterface       $logger,
    ) {
    }

    public function __invoke(PushAddressBookMessage $message): void
    {
        $book = $this->books->find($message->addressBookId);

        if (null === $book || false === $book->isActive()) {
            return;
        }

        $error  = $book->lastSyncError;
        $before = $book->syncToken;

        try {
            $pushed = $this->sync->pushPending($book);
        } catch (CalendarSyncPermanentException $e) {
            if ($error !== $e->getMessage()) {
                $this->logger->notice('AddressBookSync: push stopped until this is fixed', [
                    'addressBookId' => $book->id,
                    'error'         => $e->getMessage(),
                ]);
            }

            return;
        } catch (CalendarSyncException $e) {
            if ($error !== $e->getMessage()) {
                $this->logger->error('AddressBookSync: push failed', [
                    'addressBookId' => $book->id,
                    'error'         => $e->getMessage(),
                    'exception'     => $e,
                ]);
            }

            throw $e;
        }

        if (null !== $before && null === $book->syncToken) {
            $this->bus->dispatch(new SyncAddressBookMessage((int) $book->id));
        }

        if (0 < $pushed) {
            $this->logger->info('AddressBookSync: pending contacts pushed', [
                'addressBookId' => $book->id,
                'cards'         => $pushed,
            ]);
        }
    }
}
