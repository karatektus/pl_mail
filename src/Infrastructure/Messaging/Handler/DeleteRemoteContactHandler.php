<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Infrastructure\Messaging\Message\DeleteRemoteContactMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Service\AddressBook\AddressBookDriverRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes a contact on its server — CardDAV, Google or Outlook, whichever the
 * book's driver speaks — after it was removed here outright: merged into
 * another, or purged from the bin. See ContactSyncTracker.
 *
 * Gone already (404, 410) is done. A 412 means the card was changed elsewhere
 * after we last read it; the rule everywhere else is that the server wins a
 * conflict, so the card stays, and the next pull brings it back as a contact.
 * A transient failure escapes for the transport's retry; a permanent one (a
 * revoked password) is logged and dropped, as there is no row left to record
 * it on.
 */
#[AsMessageHandler]
final readonly class DeleteRemoteContactHandler
{
    public function __construct(
        private AddressBookRepository $books,
        private AddressBookDriverRegistry $drivers,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteRemoteContactMessage $message): void
    {
        $book = $this->books->find($message->addressBookId);

        if (null === $book) {
            // Unsubscribed since: the book's cards are no longer ours to delete.
            return;
        }

        try {
            $this->drivers->forBook($book)->delete($book, $message->href, $message->etag);
        } catch (CalendarResyncRequiredException $e) {
            if (412 !== $e->getStatus()) {
                throw $e;
            }

            $this->logger->warning('CardDav: a deleted contact had changed on the server; keeping its card', [
                'addressBook' => $book->id,
                'href'        => $message->href,
            ]);
        } catch (CalendarSyncPermanentException $e) {
            $this->logger->error('CardDav: could not delete a card on the server', [
                'addressBook' => $book->id,
                'href'        => $message->href,
                'reason'      => $e->getMessage(),
            ]);
        }
    }
}
