<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Graph;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Domain\DTO\AddressBook\RemoteContactWrite;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\AddressBookDriverInterface;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\Account;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;

/**
 * A Microsoft account's Outlook contacts, through Graph, behind the
 * address-book driver contract.
 *
 * Rides the mail account's own OAuth grant (MailProvider::scopes() asks for
 * Contacts.ReadWrite beside the mailbox and the calendars), as
 * GraphCalendarSyncDriver does.
 *
 * **One address book per contact folder** — `/me/contactFolders`, which
 * lists the default Contacts folder and the folders beside it. AddressBook::
 * $remoteId is the folder id (immutable, see GraphContactsClient). Folders
 * nested inside another folder are not offered.
 *
 * **Sync** is the folder's delta query: a null token is a fresh round, which
 * lists every contact and so is a full listing; the @odata.deltaLink it ends
 * on is the book's sync token, followed verbatim next time. A removed contact
 * arrives as its id with `@removed`. Exchange keeps delta tokens for as long
 * as its cache holds them, and an expired one is read again from scratch.
 *
 * **Writes** are a POST into the folder, a PATCH with If-Match on the
 * contact's etag (a 412 is a conflict, Outlook's version wins), and a DELETE.
 * What Outlook's fixed places cannot hold is GraphContactMapper's subject.
 *
 * **Photos** are read from the contact's /photo/$value whenever the contact
 * changed, and a photo changed here is sent there after the contact itself.
 * Graph cannot remove an Outlook contact's photo — its delete is for user
 * and group photos only — so a photo removed here stays in Outlook, and the
 * next change there brings it back.
 *
 * Not synced: the organisation's directory (a different API and a different
 * permission).
 */
final readonly class GraphContactsDriver implements AddressBookDriverInterface
{
    public function __construct(
        private GraphContactsClient $client,
        private GraphContactMapper  $mapper,
        private ContactPhotoStore   $photos,
    ) {
    }

    public function handles(AddressBookSource $source): bool
    {
        return MailProvider::Microsoft === $source->mailProvider();
    }

    public function discover(AddressBookSource $source): array
    {
        if (null === $source->account) {
            return [];
        }

        $folders = $this->client->collect($source->account, GraphContactsClient::ME . '/contactFolders', ['$select' => 'id,displayName'], 'listing the contact folders');
        $books   = [];

        foreach ($folders['items'] as $folder) {
            if (is_string($folder['id'] ?? null) && '' !== $folder['id']) {
                $books[] = new RemoteAddressBook($folder['id'], is_string($folder['displayName'] ?? null) ? $folder['displayName'] : 'Contacts', false);
            }
        }

        return $books;
    }

    public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet
    {
        $account = $this->accountOf($book);
        $round   = null === $syncToken
            ? $this->client->collect($account, $this->folderUrl($book) . '/contacts/delta', ['$select' => GraphContactMapper::SELECT], 'reading the contacts')
            : $this->client->collect($account, $syncToken, [], 'reading what changed in the contacts');

        $cards = [];

        foreach ($round['items'] as $item) {
            if (false === is_string($item['id'] ?? null) || '' === $item['id']) {
                continue;
            }

            if (true === array_key_exists('@removed', $item)) {
                $cards[] = RemoteCard::deleted($item['id']);

                continue;
            }

            $cards[] = RemoteCard::parsed($item['id'], $this->mapper->etagOf($item), $this->mapper->toParsed($item));
        }

        return AddressBookChangeSet::of($cards, $round['deltaLink'] ?? $syncToken, null === $syncToken);
    }

    public function put(AddressBook $book, Contact $contact): RemoteContactWrite
    {
        $account = $this->accountOf($book);
        $body    = $this->mapper->toGraph($contact);

        if (null === $contact->href) {
            $written = $this->client->call($account, 'POST', $this->folderUrl($book) . '/contacts', ['json' => $body], 'adding a contact');
        } else {
            $headers = null === $contact->etag ? [] : ['If-Match' => $contact->etag];
            $written = $this->client->call($account, 'PATCH', GraphContactsClient::ME . '/contacts/' . rawurlencode($contact->href), [
                'json'    => $body,
                'headers' => $headers,
            ], 'saving a contact');
        }

        $id = is_string($written['id'] ?? null) ? $written['id'] : $contact->href;

        if (null === $id) {
            throw new CalendarSyncException('Microsoft Graph saved the contact without saying where it is.');
        }

        $etag  = $this->mapper->etagOf($written);
        $photo = true === $contact->photoDirty || (null === $contact->href && null !== $contact->photoPath)
            ? $this->photos->read($contact->photoPath)
            : null;

        // A new photo changes the contact's version; the etag kept is the
        // one after it, or the next PATCH would be refused as a conflict.
        if (null !== $photo) {
            $this->client->putPhoto($account, $id, $photo);
            $after = $this->client->call($account, 'GET', GraphContactsClient::ME . '/contacts/' . rawurlencode($id), ['query' => ['$select' => 'id']], 'reading a contact');
            $etag  = $this->mapper->etagOf($after) ?? $etag;
        }

        return new RemoteContactWrite(href: $id, etag: $etag);
    }

    public function photo(AddressBook $book, RemoteCard $card): ?ContactPhoto
    {
        return $this->client->photo($this->accountOf($book), $card->href);
    }

    public function delete(AddressBook $book, ?string $href, ?string $etag): void
    {
        if (null === $href) {
            return;
        }

        $this->client->call($this->accountOf($book), 'DELETE', GraphContactsClient::ME . '/contacts/' . rawurlencode($href), [], 'deleting a contact', [404]);
    }

    private function folderUrl(AddressBook $book): string
    {
        return GraphContactsClient::ME . '/contactFolders/' . rawurlencode($book->remoteId);
    }

    private function accountOf(AddressBook $book): Account
    {
        return $book->account ?? throw new CalendarSyncPermanentException(sprintf('Address book #%d is not attached to a Microsoft account.', (int) $book->id));
    }
}
