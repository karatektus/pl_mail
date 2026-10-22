<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Which of a connection's or a mail account's address books are mirrored —
 * CalendarSubscriber's counterpart, and the same rules, for every kind of
 * server: the driver for the holder lists what is there.
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
        private AddressBookDriverRegistry $drivers,
        private AddressBookRepository  $books,
        private EntityManagerInterface $em,
        private MessageBusInterface    $bus,
    ) {
    }

    /**
     * What the connection or account offers, each paired with the row mirroring it.
     *
     * `elsewhere` is the same book mirrored through another of the user's
     * connections or accounts — a separate CardDAV connection to the same
     * Nextcloud, say. Remote ids are absolute URLs for CardDAV, so both reach
     * the book under one id, and nothing else would notice: the unique index is
     * per holder. Ticking it here would show every contact twice (#317), so the
     * list offers it locked and apply() skips it. Null whenever `local` is set:
     * a book this holder already mirrors is its own, whoever else has it.
     *
     * @return list<array{remote: RemoteAddressBook, local: AddressBook|null, elsewhere: AddressBook|null}>
     *
     * @throws CalendarSyncException
     */
    public function discover(Integration|Account $holder): array
    {
        $mirrored = [];

        foreach ($this->books->findForHolder($holder) as $book) {
            $mirrored[$book->remoteId] = $book;
        }

        $source  = $holder instanceof Integration ? AddressBookSource::ofIntegration($holder) : AddressBookSource::ofAccount($holder);
        $remotes = $this->drivers->for($source)->discover($source);
        $other   = $this->mirroredElsewhere($holder, $remotes);

        $offers = [];

        foreach ($remotes as $remote) {
            $local = $mirrored[$remote->remoteId] ?? null;

            $offers[] = [
                'remote'    => $remote,
                'local'     => $local,
                'elsewhere' => null === $local ? $other[$remote->remoteId] ?? null : null,
            ];
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
    public function apply(Integration|Account $holder, array $wantedRemoteIds): array
    {
        $wanted       = array_flip($wantedRemoteIds);
        $fresh        = [];
        $unsubscribed = 0;

        foreach ($this->discover($holder) as $offer) {
            $remote   = $offer['remote'];
            $local    = $offer['local'];
            $isWanted = true === array_key_exists($remote->remoteId, $wanted);

            // Already mirrored through another holder: a second row would show
            // every contact twice. The list draws this box locked; this is the
            // server half, as CalendarSubscriber does for calendars.
            if (null !== $offer['elsewhere']) {
                continue;
            }

            if (true === $isWanted && null === $local) {
                $book             = new AddressBook($holder->usr ?? throw new \LogicException('An account with no user cannot hold an address book.'), $holder, $remote->remoteId, $remote->name);
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

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The same remotes, mirrored by another of this user's holders, keyed by
     * remote id. CalendarDiscoverer::mirroredElsewhere() for address books.
     *
     * @param list<RemoteAddressBook> $remotes
     *
     * @return array<string, AddressBook>
     */
    private function mirroredElsewhere(Integration|Account $holder, array $remotes): array
    {
        $user = $holder->usr;

        if (null === $user || [] === $remotes) {
            return [];
        }

        $ids    = array_map(static fn (RemoteAddressBook $remote): string => $remote->remoteId, $remotes);
        $others = [];

        foreach ($this->books->findForUserByRemoteIds($user, $ids) as $book) {
            $isThisHolder = $holder instanceof Integration
                ? $book->integration === $holder
                : $book->account === $holder;

            if (false === $isThisHolder) {
                $others[$book->remoteId] = $book;
            }
        }

        return $others;
    }
}
