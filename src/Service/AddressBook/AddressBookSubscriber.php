<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Service\AddressBook\CardDav\CardDavDriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Which of a connection's address books are mirrored — CalendarSubscriber's
 * counterpart, and the same rules.
 *
 * The ticked remote ids are the whole instruction: ticked and not mirrored
 * gets a row, mirrored and not ticked loses it, the rest is left alone.
 * **Nothing the browser posted describes a book** — names and the read-only
 * flag come from a fresh discovery, so a crafted POST cannot mint a writable
 * copy of a book the server refuses writes to.
 *
 * Unsubscribing deletes the row and its cards by cascade. They are copies; the
 * server holds the originals. An edit made here and not yet pushed is lost
 * with them, and the confirmation in the UI says so.
 */
final readonly class AddressBookSubscriber
{
    public function __construct(
        private CardDavDriver          $driver,
        private AddressBookRepository  $books,
        private EntityManagerInterface $em,
        private MessageBusInterface    $bus,
    ) {
    }

    /**
     * What the connection offers, each paired with the row mirroring it.
     *
     * @return list<array{remote: RemoteAddressBook, local: AddressBook|null}>
     *
     * @throws CalendarSyncException
     */
    public function discover(Integration $integration): array
    {
        $mirrored = [];

        foreach ($this->books->findForIntegration($integration) as $book) {
            $mirrored[$book->remoteId] = $book;
        }

        $offers = [];

        foreach ($this->driver->discover($integration) as $remote) {
            $offers[] = ['remote' => $remote, 'local' => $mirrored[$remote->remoteId] ?? null];
        }

        return $offers;
    }

    /**
     * Bring the mirrored set into line with what was ticked.
     *
     * @param list<string> $wantedRemoteIds
     *
     * @return array{subscribed: int, unsubscribed: int}
     *
     * @throws CalendarSyncException when the server cannot be listed — and
     *                               nothing is written then, because a partial
     *                               listing would read as "these books are
     *                               gone" and unsubscribe from them
     */
    public function apply(Integration $integration, array $wantedRemoteIds): array
    {
        $wanted       = array_flip($wantedRemoteIds);
        $fresh        = [];
        $unsubscribed = 0;

        foreach ($this->discover($integration) as $offer) {
            $remote   = $offer['remote'];
            $local    = $offer['local'];
            $isWanted = true === array_key_exists($remote->remoteId, $wanted);

            if (true === $isWanted && null === $local) {
                $book             = new AddressBook($integration->usr, $integration, $remote->remoteId, $remote->name);
                $book->isReadOnly = $remote->isReadOnly;
                $this->em->persist($book);
                $fresh[] = $book;

                continue;
            }

            if (null !== $local) {
                if (false === $isWanted) {
                    $this->em->remove($local);
                    ++$unsubscribed;

                    continue;
                }

                // Still mirrored: the server's current answers win over what
                // was true when it was first ticked.
                $local->name       = $remote->name;
                $local->isReadOnly = $remote->isReadOnly;
            }
        }

        $this->em->flush();

        // After the flush, so each book has an id to put in the message. A
        // first sync now rather than at the next sweep: somebody who has just
        // ticked their contacts expects to see them.
        foreach ($fresh as $book) {
            $this->bus->dispatch(new SyncAddressBookMessage((int) $book->id));
        }

        return ['subscribed' => count($fresh), 'unsubscribed' => $unsubscribed];
    }
}
