<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use App\Repository\AddressBook\ContactCardRepository;
use App\Service\AddressBook\CardDav\CardDavDriver;
use App\Service\AddressBook\CardDav\VCardConverter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bringing one address book and its server back into agreement.
 *
 * **Push first, then pull.** A local edit is written to the database the moment
 * it is made and marked pending; this sends what is pending and only then asks
 * the server what changed. The other order would pull a card the user has just
 * edited here, find the server's older copy, and overwrite the edit with it
 * before it ever left.
 *
 * **On a conflict the server wins.** A 412 on a PUT means the card changed
 * elsewhere — on a phone, in Nextcloud — after we last read it. Merging two
 * vCards field by field is a feature of its own and getting it wrong corrupts
 * somebody's address book; keeping the server's version loses one edit made
 * here, and the log says which. The card is marked clean with no etag, so the
 * pull that follows overwrites it with what the server holds.
 *
 * **A full listing is authoritative, a delta is not.** Cards the server did not
 * mention are deleted locally only when the pull asked with no token and the
 * driver says the answer is complete; see AddressBookChangeSet. Pending cards
 * are never deleted that way — they are this side's news, not the server's.
 *
 * Failures are recorded on the book (for the settings list) and rethrown, so
 * the handler can leave the retry decision to the exception's class, as the
 * calendar sync does.
 */
final readonly class AddressBookSync
{
    public function __construct(
        private CardDavDriver          $driver,
        private VCardConverter         $converter,
        private ContactCardRepository  $cards,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * @return int how many cards were pushed, written or removed
     *
     * @throws CalendarSyncException
     */
    public function sync(AddressBook $book): int
    {
        try {
            $touched = $this->push($book);
            $touched += $this->pull($book);
        } catch (CalendarSyncException $e) {
            $book->recordSyncFailure($e->getMessage());
            $this->em->flush();

            throw $e;
        }

        $book->recordSyncSuccess();
        $this->em->flush();

        return $touched;
    }

    // ── Push ──────────────────────────────────────────────────────────────────

    /**
     * @throws CalendarSyncException
     */
    private function push(AddressBook $book): int
    {
        $pushed = 0;

        foreach ($this->cards->findPending($book) as $card) {
            try {
                if (SyncState::PendingDelete === $card->syncState) {
                    $this->driver->delete($book, $card);
                    $this->em->remove($card);
                } elseif (true === $book->isReadOnly) {
                    // Nothing in the UI lets this happen, so it is a book the
                    // server has since made read-only. The edit cannot go
                    // anywhere; the next pull puts the server's copy back.
                    $this->logger->warning('CardDav: dropped a local edit to a read-only address book', [
                        'addressBook' => $book->id,
                        'card'        => $card->uid,
                    ]);
                    $this->letTheServerWin($card);
                } else {
                    $written     = $this->driver->put($book, $card);
                    $card->href  = $written['href'];
                    $card->etag  = $written['etag'];
                    $card->syncState = SyncState::Clean;
                }
            } catch (CalendarResyncRequiredException $e) {
                $this->logger->warning('CardDav: the server changed a contact we had edited; keeping its version', [
                    'addressBook' => $book->id,
                    'card'        => $card->uid,
                    'name'        => $card->fullName,
                ]);
                $this->letTheServerWin($card);
            }

            ++$pushed;
        }

        // Flushed before the pull reads its index of the book, so a card just
        // created has its href there and is matched rather than duplicated.
        $this->em->flush();

        return $pushed;
    }

    /**
     * Forget the local edit so the pull overwrites it.
     *
     * A card created here that collided with one already on the server keeps
     * no href; the pull then finds the server's card by UID and adopts it.
     * Forcing a full listing is what guarantees the pull mentions it at all —
     * a delta since our token might not, if the collision is with an old card.
     */
    private function letTheServerWin(ContactCard $card): void
    {
        $card->syncState = SyncState::Clean;
        $card->etag      = null;

        $card->addressBook->syncToken = null;
    }

    // ── Pull ──────────────────────────────────────────────────────────────────

    /**
     * @throws CalendarSyncException
     */
    private function pull(AddressBook $book): int
    {
        $changes = $this->pullOnce($book, $book->syncToken);

        if (true === $changes->resyncRequired) {
            $book->syncToken = null;
            $changes         = $this->driver->pull($book, null);
        }

        $touched = $this->apply($book, $changes);

        $book->syncToken = $changes->syncToken;

        return $touched;
    }

    /**
     * A dead token arrives two ways — as the driver's resyncRequired outcome
     * (a moved ctag) or as an exception (the `valid-sync-token` precondition,
     * a 507 on the report) — and both mean the same thing: read everything
     * again. Normalised here, as CalendarSyncService::pullOnce() does, so the
     * token is actually dropped. Let through, the exception failed the sync
     * with the dead token still stored, and every retry presented it again.
     *
     * Only for a pull that sent a token. Asked from scratch, the server has no
     * position of ours to reject, and the failure is reported as itself.
     *
     * @throws CalendarSyncException
     */
    private function pullOnce(AddressBook $book, ?string $syncToken): AddressBookChangeSet
    {
        try {
            return $this->driver->pull($book, $syncToken);
        } catch (CalendarResyncRequiredException $e) {
            if (null === $syncToken) {
                throw $e;
            }

            $this->logger->info('CardDav: sync position expired, reading the address book from scratch', [
                'addressBook' => $book->id,
            ]);

            return AddressBookChangeSet::resyncRequired();
        }
    }

    /**
     * Removals are collected and applied last. Doctrine flushes inserts before
     * deletes, so a server that deleted a card and re-created it under a new
     * href with the same UID — which is what "move" looks like to CardDAV —
     * would otherwise have its new row inserted while the old one still holds
     * the UID, and the unique key would fail the whole sync. Deferring lets the
     * incoming card adopt the row that was about to go instead.
     *
     * The two maps are kept current as rows are adopted and created, not
     * read once. A report lists the move in either order, and with the old
     * href still pointing at an adopted row, a deletion reported *after* the
     * re-creation would doom the row that had just taken the new href — the
     * contact would vanish here while it still exists on the server, until
     * the next full listing.
     */
    private function apply(AddressBook $book, AddressBookChangeSet $changes): int
    {
        $index   = $this->cards->indexForBook($book);
        $byHref  = $index['byHref'];
        $byUid   = $index['byUid'];
        $seen    = [];
        $doomed  = [];
        $touched = 0;

        foreach ($changes->cards as $remote) {
            $seen[$remote->href] = true;
            $local               = $byHref[$remote->href] ?? null;

            if (true === $remote->isDeleted()) {
                if (null !== $local && false === $local->syncState->isPending()) {
                    $doomed[spl_object_id($local)] = $local;
                }

                continue;
            }

            // Unchanged since we last wrote or read it.
            if (null !== $local && null !== $remote->etag && $remote->etag === $local->etag) {
                continue;
            }

            $fields = $this->converter->read((string) $remote->vcard, $remote->href);

            if (null === $fields) {
                $this->logger->info('CardDav: skipped a resource that is not a usable vCard', ['href' => $remote->href]);

                continue;
            }

            $local ??= $byUid[$fields->uid] ?? null;

            // A local edit still waiting to go out is this side's news; the
            // server's copy does not overwrite it.
            if (null !== $local && true === $local->syncState->isPending()) {
                continue;
            }

            if (null === $local) {
                $local = new ContactCard($book, $fields->uid);
                $this->em->persist($local);
                $byUid[$local->uid] = $local;
            }

            unset($doomed[spl_object_id($local)]);

            if (null !== $local->href && $local->href !== $remote->href) {
                unset($byHref[$local->href]);
            }

            $byHref[$remote->href] = $local;

            $local->href  = $remote->href;
            $local->etag  = $remote->etag;
            $local->vcard = (string) $remote->vcard;
            $local->fill($fields);
            ++$touched;
        }

        if (true === $changes->isFullListing) {
            foreach ($byUid as $local) {
                if (true === $local->syncState->isPending()) {
                    continue;
                }

                // Gone from the server — or never there: a card created here
                // that lost a collision and was not adopted by anything above.
                if (null === $local->href || false === array_key_exists($local->href, $seen)) {
                    $doomed[spl_object_id($local)] = $local;
                }
            }
        }

        foreach ($doomed as $local) {
            $this->em->remove($local);
            ++$touched;
        }

        return $touched;
    }
}
