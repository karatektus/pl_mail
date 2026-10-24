<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Domain\DTO\AddressBook\RemoteContactWrite;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Service\Contacts\ContactPhoto;

/**
 * Talks to one kind of remote address book — CalendarSyncDriverInterface's
 * counterpart, and held to the same contracts, briefly:
 *
 *   - **Every id is opaque.** AddressBook::$remoteId, $syncToken, a contact's
 *     $href, $etag and $remoteResource come back to the driver byte for byte.
 *   - **ParsedContact is the only contact vocabulary above this line.** A
 *     CardDAV driver may hand back the vCard for AddressBookSync to parse (it
 *     keeps the card to patch); every other driver parses its own format.
 *   - **Every failure is a CalendarSyncException or a subclass**, classified
 *     the way the calendar drivers classify theirs: permanent, throttled,
 *     resync-required (a dead sync position, or a write the server refused
 *     because the contact changed there first), or the unclassified base.
 *   - **Nothing here flushes, persists or writes an entity.** put() reports
 *     what to store in a RemoteContactWrite.
 *
 * Implementations are auto-tagged app.address_book_driver and resolved through
 * AddressBookDriverRegistry, which takes the first that handles() a source.
 * handles(), not supports(): the CardDAV driver is also an integration driver
 * (VerifiableDriverInterface::supports(Provider)), and one class cannot carry
 * two supports() with different arguments.
 *
 * A driver is never asked to write to a book whose isReadOnly is true, and
 * never called concurrently for the same book: one message per book, on one
 * queue at a time.
 */
interface AddressBookDriverInterface
{
    /** Whether this driver speaks for the account or connection. No I/O. */
    public function handles(AddressBookSource $source): bool;

    /**
     * Every address book the source can see, mirrored or not.
     *
     * @return list<RemoteAddressBook>
     *
     * @throws CalendarSyncException
     */
    public function discover(AddressBookSource $source): array;

    /**
     * What changed since $syncToken; everything, as a full listing, for null.
     * A dead token is AddressBookChangeSet::resyncRequired() (or the
     * CalendarResyncRequiredException, handled the same).
     *
     * @throws CalendarSyncException
     */
    public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet;

    /**
     * Create the contact (no $href yet) or update it, conditional on its etag.
     * A conflict is a CalendarResyncRequiredException with status 412,
     * whatever the provider itself answered — that is what AddressBookSync
     * reads as "the server wins".
     *
     * @throws CalendarSyncException
     */
    public function put(AddressBook $book, Contact $contact): RemoteContactWrite;

    /**
     * The photo of a pulled contact whose ParsedContact said $photoDeferred:
     * a provider that serves photos apart from the contact (Google's photo
     * URL, Graph's /photo/$value). Null when the contact has none. A driver
     * that never defers answers null.
     *
     * Asked only when the photo's version changed (or the driver gives none),
     * and only after the contact's own put() for a write: put() sends the
     * photo itself when Contact::$photoDirty says so.
     *
     * @throws CalendarSyncException
     */
    public function photo(AddressBook $book, RemoteCard $card): ?ContactPhoto;

    /**
     * Delete one contact. Idempotent: one already gone is deleted.
     *
     * @throws CalendarSyncException
     */
    public function delete(AddressBook $book, ?string $href, ?string $etag): void;
}
