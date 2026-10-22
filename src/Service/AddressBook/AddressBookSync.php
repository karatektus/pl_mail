<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Repository\Contacts\ContactRepository;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Contacts\ParsedContact;
use App\Service\Contacts\VCardMapper;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Bringing one address book and its server back into agreement — any server:
 * the driver for the book's source (AddressBookDriverRegistry) speaks CardDAV,
 * the People API or Graph, and everything here is the same for all three.
 *
 * **One run at a time per book** (AddressBookLock): the push on `export` and
 * the sweep on `ingest` can otherwise overlap, and two runs pushing the same
 * new contact would create it twice on a server with no If-None-Match.
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
 * **What a card is here.** Each card is a Contact in this book: its rows are
 * what the contacts page, JMAP and autocomplete read, and the card itself is
 * kept beside them so a push patches it rather than rebuilding it (see
 * Contact and VCardMapper). The whole run is untracked: what it writes is what
 * the server already has, and ContactSyncTracker must not take it for an edit
 * to send back.
 *
 * **The push also runs alone.** An edit here is carried out by pushPending(),
 * on the export queue with everything else leaving plMail; the sweep and a
 * book's first sync run sync(), on ingest. See PushAddressBookMessage.
 *
 * Failures are recorded on the book (for the settings list) and rethrown, so
 * the handler can leave the retry decision to the exception's class, as the
 * calendar sync does.
 */
final readonly class AddressBookSync
{
    public function __construct(
        private AddressBookDriverRegistry $drivers,
        private AddressBookLock        $lock,
        private VCardMapper            $mapper,
        private ContactRepository      $cards,
        private ContactPhotoStore      $photos,
        private ContactSyncTracker     $tracker,
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
        return $this->lock->exclusively($book, fn (): int => $this->tracker->untracked(fn (): int => $this->syncUntracked($this->fresh($book))));
    }

    /**
     * Send what is pending and read nothing back: the push after an edit
     * here, on the export queue (see PushAddressBookMessage). The pull is the
     * sweep's.
     *
     * A success does not touch lastSyncedAt. That is what the sweep reads,
     * and a book pushed to has not been brought up to date — moved on, the
     * sweep would skip the pull every edit is followed by. A failure is
     * recorded as sync() records it, so the settings list says what is wrong.
     *
     * @return int how many cards were pushed
     *
     * @throws CalendarSyncException
     */
    public function pushPending(AddressBook $book): int
    {
        return $this->lock->exclusively($book, fn (): int => $this->tracker->untracked(function () use ($book): int {
            $book = $this->fresh($book);

            try {
                return $this->push($book);
            } catch (CalendarSyncException $e) {
                $book->recordSyncFailure($e->getMessage());
                $this->em->flush();

                throw $e;
            }
        }));
    }

    /**
     * The book as the database has it now. A run that waited for the lock
     * may hold a copy from before the other run moved the sync position.
     */
    private function fresh(AddressBook $book): AddressBook
    {
        if (null !== $book->id && true === $this->em->contains($book)) {
            $this->em->refresh($book);
        }

        return $book;
    }

    /**
     * @throws CalendarSyncException
     */
    private function syncUntracked(AddressBook $book): int
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
        $driver = null;

        foreach ($this->cards->findPending($book) as $card) {
            // Nothing in the UI lets this happen, so it is a book the server
            // has since made read-only. The edit cannot go anywhere; the next
            // pull puts the server's copy back. Checked before deletes as well
            // as writes: a DELETE there is refused like any other write.
            if (true === $book->isReadOnly) {
                $this->logger->warning('AddressBookSync: dropped a local edit to a read-only address book', [
                    'addressBook' => $book->id,
                    'card'        => $card->uid,
                ]);
                $this->letTheServerWin($card);
                ++$pushed;

                continue;
            }

            $driver ??= $this->drivers->forBook($book);

            try {
                if (SyncState::PendingDelete === $card->syncState) {
                    $driver->delete($book, $card->href, $card->etag);
                    $this->letGo($card);
                } else {
                    // What was sent, as the driver reports it: the patched
                    // vCard for CardDAV, an opaque resource for the others.
                    $written              = $driver->put($book, $card);
                    $card->href           = $written->href;
                    $card->etag           = $written->etag;
                    $card->vcard          = $written->vcard ?? $card->vcard;
                    $card->remoteResource = $written->resource ?? $card->remoteResource;
                    $card->syncState      = SyncState::Clean;
                    $card->photoDirty     = false;
                }
            } catch (CalendarResyncRequiredException $e) {
                // Only a 412 is about this card. The same class also carries a
                // 507 from CalDavClient::classify() — on a PUT that is the
                // server's quota, not somebody else's edit — and letting the
                // server win there would discard the edit for a reason that
                // has nothing to do with it. Left pending, the sync fails, and
                // the transport's retry offers it again.
                if (412 !== $e->getStatus()) {
                    throw $e;
                }

                $this->logger->warning('AddressBookSync: the server changed a contact we had edited; keeping its version', [
                    'addressBook' => $book->id,
                    'card'        => $card->uid,
                    'name'        => $card->displayName,
                ]);
                $this->letTheServerWin($card);
            } catch (CalendarSyncPermanentException $e) {
                // A 403 on one card is the server refusing *this* write — most
                // often a book whose privilege set was absent, so it was taken
                // as writable (see DavResource::isReadOnly()). Left pending,
                // the same PUT would be refused on every run and the pull
                // behind it would never happen again: one card would stop the
                // whole book syncing. CalendarPusher::abandon() makes the same
                // call for events. Every other permanent failure — a rejected
                // password, an address refused by the SSRF guard — is about the
                // connection, and the edit is kept for when it is fixed.
                if (403 !== $e->getStatus()) {
                    throw $e;
                }

                $this->logger->error('AddressBookSync: the server refused a contact edit, giving up on it', [
                    'addressBook' => $book->id,
                    'card'        => $card->uid,
                    'syncState'   => $card->syncState->value,
                    'reason'      => $e->getMessage(),
                    'discarded'   => $card->vcard ?? $card->remoteResource,
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
     *
     * A refused delete is the same rule: the card is still on the server, so
     * the contact comes back out of the bin with whatever the server holds.
     */
    private function letTheServerWin(Contact $card): void
    {
        $card->syncState  = SyncState::Clean;
        $card->etag       = null;
        $card->trashedAt  = null;
        $card->photoDirty = false;
        // So the pull takes the server's photo even if it is the one we had.
        $card->photoVersion = null;

        if (null !== $card->addressBook) {
            $card->addressBook->syncToken = null;
        }
    }

    /**
     * The card is gone from the server; the contact stays in the bin, as one
     * of plMail's own, so it can still be restored there. Keeping it in the
     * book would have the next full listing find no card for it and delete
     * the row, bin and all.
     */
    private function letGo(Contact $card): void
    {
        $card->addressBook = null;
        $card->href        = null;
        $card->etag        = null;
        $card->syncState   = SyncState::Clean;
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
            $changes         = $this->drivers->forBook($book)->pull($book, null);
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
            return $this->drivers->forBook($book)->pull($book, $syncToken);
        } catch (CalendarResyncRequiredException $e) {
            if (null === $syncToken) {
                throw $e;
            }

            $this->logger->info('AddressBookSync: sync position expired, reading the address book from scratch', [
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

            $parsed = $remote->parsed ?? $this->mapper->read((string) $remote->vcard);

            if (null === $parsed) {
                $this->logger->info('AddressBookSync: skipped a contact that could not be read', ['href' => $remote->href]);

                continue;
            }

            // A card without a UID keeps one identity by its href.
            $parsed->uid ??= $remote->href;

            $local ??= $byUid[$parsed->uid] ?? null;

            // A local edit still waiting to go out is this side's news; the
            // server's copy does not overwrite it.
            if (null !== $local && true === $local->syncState->isPending()) {
                continue;
            }

            if (null === $local) {
                $local              = Contact::create($book->usr, $this->mapper->displayNameOf($parsed));
                $local->addressBook = $book;
                $local->source      = $this->sourceNameOf($book);
                $this->em->persist($local);
            }

            $this->mapper->apply($parsed, $local);
            $byUid[$local->uid] = $local;

            unset($doomed[spl_object_id($local)]);

            if (null !== $local->href && $local->href !== $remote->href) {
                unset($byHref[$local->href]);
            }

            $byHref[$remote->href] = $local;

            $local->href  = $remote->href;
            $local->etag  = $remote->etag;
            $local->vcard          = $remote->vcard;
            $local->remoteResource = $remote->resource;
            $this->takePhoto($book, $remote, $parsed, $local);
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
            if (null !== $local->photoPath) {
                $this->photos->delete($local->photoPath);
            }

            $this->em->remove($local);
            ++$touched;
        }

        return $touched;
    }

    /** What Contact::$source says a pulled contact came from. */
    private function sourceNameOf(AddressBook $book): string
    {
        return $book->source()->mailProvider()->value ?? 'carddav';
    }

    /**
     * Make the photo shown here the server's: stored when it brought one,
     * removed when it says there is none, left alone when it cannot say (a
     * vCard PHOTO that is a link). A photo with the version stored already is
     * not stored again; one the driver serves separately is fetched only then.
     *
     * A photo that cannot be fetched or stored — the request failed, the
     * image is too large or not one GD reads — leaves the contact as it was:
     * the next change to the contact on the server tries again.
     */
    private function takePhoto(AddressBook $book, RemoteCard $remote, ParsedContact $parsed, Contact $local): void
    {
        if (true === $parsed->photoAbsent) {
            $this->dropPhoto($local);

            return;
        }

        // The same photo, still on disk: nothing to do. A file that has gone
        // missing is fetched again even when the version says it is current.
        if (null !== $parsed->photoVersion && $parsed->photoVersion === $local->photoVersion && true === $this->photos->exists($local->photoPath)) {
            return;
        }

        if (null !== $parsed->photoBytes && null !== $parsed->photoMime) {
            $photo = new ContactPhoto($parsed->photoBytes, $parsed->photoMime);
        } elseif (true === $parsed->photoDeferred) {
            try {
                $photo = $this->drivers->forBook($book)->photo($book, $remote);
            } catch (CalendarSyncException $e) {
                $this->logger->info('AddressBookSync: could not read a contact photo; keeping the one shown', [
                    'addressBook' => $book->id,
                    'card'        => $local->uid,
                    'reason'      => $e->getMessage(),
                ]);

                return;
            }

            if (null === $photo) {
                $this->dropPhoto($local);

                return;
            }
        } else {
            return;
        }

        if (null === $local->usr->id) {
            return;
        }

        try {
            $path = $this->photos->storeBytes($photo->bytes, $photo->mime, $local->usr->id);
        } catch (InvalidArgumentException) {
            return;
        }

        if (null !== $local->photoPath) {
            $this->photos->delete($local->photoPath);
        }

        $local->photoPath    = $path;
        $local->photoVersion = $parsed->photoVersion;
    }

    private function dropPhoto(Contact $local): void
    {
        if (null !== $local->photoPath) {
            $this->photos->delete($local->photoPath);
        }

        $local->photoPath    = null;
        $local->photoVersion = null;
    }
}
