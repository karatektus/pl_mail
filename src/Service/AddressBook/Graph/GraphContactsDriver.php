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
 * Not synced: photos, and the organisation's directory (a different API and
 * a different permission).
 */
final readonly class GraphContactsDriver implements AddressBookDriverInterface
{
    public function __construct(
        private GraphContactsClient $client,
        private GraphContactMapper  $mapper,
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

        return new RemoteContactWrite(href: $id, etag: $this->mapper->etagOf($written));
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
