<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Google;

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
 * A Google account's contacts, through the People API, behind the
 * address-book driver contract.
 *
 * Rides the mail account's own OAuth grant, as GoogleCalendarSyncDriver does:
 * MailProvider::scopes() asks for the contacts scope alongside the mailbox, so
 * a Google account connected to plMail brings its contacts with it. An account
 * connected before that holds a grant without it, and is told to reconnect
 * once (see AccountHealthInspector).
 *
 * **One address book per account.** The People API has one set of contacts
 * ("My contacts"); its groups are tags here, not books — see
 * GoogleContactMapper.
 *
 * **Sync** is people.connections.list: with requestSyncToken for a full read,
 * with syncToken for what changed since. Deletions come back as a person with
 * metadata.deleted. A token lives seven days after the full read, and an
 * expired one is read again from scratch.
 *
 * **Writes** are createContact, updateContact with an updatePersonFields mask
 * (only the fields plMail models, so Google keeps the rest) and deleteContact.
 * An update carries the CONTACT source's etag; a stale one is a conflict, and
 * Google's version wins, as everywhere else. Google asks for one user's writes
 * to go one at a time, which AddressBookLock already guarantees per book, and
 * there is one book per account.
 *
 * Google applies writes to sync results with a delay of minutes. Harmless
 * here: a pull that does not yet see a write sees nothing new about the
 * contact, and when it does see it, the etag is the one the write returned.
 *
 * **Photos** go both ways. Read, the contact's own photo is fetched from the
 * URL the Person names, and only when that URL changed (it does with the
 * photo). Written, a photo changed here is sent with updateContactPhoto after
 * the Person itself, or removed with deleteContactPhoto; either changes the
 * etag, so the one kept is the photo call's.
 *
 * Not synced: Google's "Other contacts" — the people you mailed, which plMail
 * already learns from the mail itself.
 */
final readonly class GoogleContactsDriver implements AddressBookDriverInterface
{
    /** The one book there is: the account's own contacts. */
    public const string BOOK = 'people/me';

    public const string BOOK_NAME = 'Google Contacts';

    private const string MY_CONTACTS = 'contactGroups/myContacts';

    private const int PAGE_SIZE = 1000;

    /** A guard against a server that keeps answering with a next page. */
    private const int MAX_PAGES = 100;

    public function __construct(
        private GooglePeopleClient  $client,
        private GoogleContactMapper $mapper,
        private ContactPhotoStore   $photos,
    ) {
    }

    public function handles(AddressBookSource $source): bool
    {
        return MailProvider::Google === $source->mailProvider();
    }

    public function discover(AddressBookSource $source): array
    {
        if (null === $source->account) {
            return [];
        }

        return [new RemoteAddressBook(self::BOOK, self::BOOK_NAME, false)];
    }

    public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet
    {
        $account = $this->accountOf($book);
        $groups  = $this->userGroups($account);
        $cards   = [];
        $query   = [
            'personFields'     => GoogleContactMapper::READ_FIELDS,
            'pageSize'         => self::PAGE_SIZE,
            'requestSyncToken' => 'true',
        ];

        if (null !== $syncToken) {
            $query['syncToken'] = $syncToken;
        }

        $next = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $body = $this->client->get($account, '/people/me/connections', $query, 'reading the contacts');

            foreach (is_array($body['connections'] ?? null) ? $body['connections'] : [] as $person) {
                if (false === is_array($person) || false === is_string($person['resourceName'] ?? null)) {
                    continue;
                }

                if (true === ($person['metadata']['deleted'] ?? false)) {
                    $cards[] = RemoteCard::deleted($person['resourceName']);

                    continue;
                }

                $cards[] = RemoteCard::parsed(
                    $person['resourceName'],
                    $this->mapper->etagOf($person),
                    $this->mapper->toParsed($person, $groups),
                    $this->mapper->resourceOf($person, $groups),
                );
            }

            $next = is_string($body['nextSyncToken'] ?? null) ? $body['nextSyncToken'] : $next;
            $pageToken = $body['nextPageToken'] ?? null;

            if (false === is_string($pageToken) || '' === $pageToken) {
                return AddressBookChangeSet::of($cards, $next ?? $syncToken, null === $syncToken);
            }

            $query['pageToken'] = $pageToken;
        }

        throw new CalendarSyncException('Google kept answering with more pages of contacts than plMail will read in one go.');
    }

    public function put(AddressBook $book, Contact $contact): RemoteContactWrite
    {
        $account     = $this->accountOf($book);
        $groups      = $this->userGroups($account);
        $stored      = $this->decode($contact->remoteResource);
        $memberships = [...$this->systemMemberships($stored), ...$this->tagMemberships($account, $contact->tags, $groups)];
        $person      = $this->mapper->toPerson($contact, $memberships, array_values(array_filter(
            is_array($stored['organizations'] ?? null) ? $stored['organizations'] : [],
            is_array(...),
        )));

        if (null === $contact->href) {
            $written = $this->client->write($account, 'POST', '/people:createContact', [
                'personFields' => GoogleContactMapper::READ_FIELDS,
            ], $person, 'adding a contact');
        } else {
            // The sources are what updateContact checks the etag against.
            // Kept from the last read; fetched now for a contact read before
            // they were kept.
            $sources = is_array($stored['sources'] ?? null) && [] !== $stored['sources']
                ? $stored['sources']
                : $this->sourcesOf($account, $contact->href);

            $person['etag']     = $contact->etag;
            $person['metadata'] = ['sources' => $sources];

            $written = $this->client->write($account, 'PATCH', '/' . $contact->href . ':updateContact', [
                'updatePersonFields' => GoogleContactMapper::UPDATE_FIELDS,
                'personFields'       => GoogleContactMapper::READ_FIELDS,
            ], $person, 'saving a contact');
        }

        $href = is_string($written['resourceName'] ?? null) ? $written['resourceName'] : $contact->href;

        if (null === $href) {
            throw new CalendarSyncException('Google saved the contact without saying where it is.');
        }

        if (true === $contact->photoDirty || (null === $contact->href && null !== $contact->photoPath)) {
            $written = $this->putPhoto($account, $href, $this->photos->read($contact->photoPath)) ?? $written;
        }

        return new RemoteContactWrite(
            href:     $href,
            etag:     $this->mapper->etagOf($written),
            resource: $this->mapper->resourceOf($written, $groups),
        );
    }

    public function photo(AddressBook $book, RemoteCard $card): ?ContactPhoto
    {
        $url = $card->parsed?->photoVersion;

        return null === $url ? null : $this->client->photo($url);
    }

    /**
     * Send the photo, or remove Google's when there is none here. Answers the
     * Person as it is after, for its new etag; null if Google did not send it.
     *
     * @return array<string, mixed>|null
     *
     * @throws CalendarSyncException
     */
    private function putPhoto(Account $account, string $href, ?ContactPhoto $photo): ?array
    {
        $body = null === $photo
            ? $this->client->call($account, 'DELETE', '/' . $href . ':deleteContactPhoto', ['personFields' => GoogleContactMapper::READ_FIELDS], 'removing a contact photo')
            : $this->client->write($account, 'PATCH', '/' . $href . ':updateContactPhoto', [], [
                'photoBytes'   => base64_encode($photo->bytes),
                'personFields' => GoogleContactMapper::READ_FIELDS,
            ], 'saving a contact photo');

        return is_array($body['person'] ?? null) ? $body['person'] : null;
    }

    public function delete(AddressBook $book, ?string $href, ?string $etag): void
    {
        if (null === $href) {
            return;
        }

        $this->client->delete($this->accountOf($book), '/' . $href . ':deleteContact', 'deleting a contact');
    }

    /**
     * The user's own groups, resourceName → name: the tags.
     *
     * @return array<string, string>
     *
     * @throws CalendarSyncException
     */
    private function userGroups(Account $account): array
    {
        $body   = $this->client->get($account, '/contactGroups', ['pageSize' => 1000, 'groupFields' => 'name,groupType'], 'reading the contact groups');
        $groups = [];

        foreach (is_array($body['contactGroups'] ?? null) ? $body['contactGroups'] : [] as $group) {
            if (is_array($group) && 'USER_CONTACT_GROUP' === ($group['groupType'] ?? null)
                && is_string($group['resourceName'] ?? null) && is_string($group['name'] ?? null)) {
                $groups[$group['resourceName']] = $group['name'];
            }
        }

        return $groups;
    }

    /**
     * The memberships a contact keeps whatever its tags say — My Contacts,
     * Starred. A contact with none is put in My Contacts: a contact in no
     * group at all is one Google's own app does not list.
     *
     * @param array<string, mixed> $stored
     *
     * @return list<array<string, mixed>>
     */
    private function systemMemberships(array $stored): array
    {
        $kept = array_values(array_filter(
            is_array($stored['memberships'] ?? null) ? $stored['memberships'] : [],
            static fn (mixed $m): bool => is_array($m) && '' !== (string) ($m['contactGroupMembership']['contactGroupResourceName'] ?? ''),
        ));

        return [] === $kept
            ? [['contactGroupMembership' => ['contactGroupResourceName' => self::MY_CONTACTS]]]
            : $kept;
    }

    /**
     * A membership per tag, creating the group for a tag Google has no group
     * of that name for.
     *
     * @param list<string>          $tags
     * @param array<string, string> $groups
     *
     * @return list<array<string, mixed>>
     *
     * @throws CalendarSyncException
     */
    private function tagMemberships(Account $account, array $tags, array $groups): array
    {
        $byName      = array_flip($groups);
        $memberships = [];

        foreach (array_unique($tags) as $tag) {
            $resource = $byName[$tag] ?? null;

            if (null === $resource) {
                $created  = $this->client->write($account, 'POST', '/contactGroups', [], ['contactGroup' => ['name' => $tag]], 'adding a contact group');
                $resource = is_string($created['resourceName'] ?? null) ? $created['resourceName'] : null;
            }

            if (null !== $resource) {
                $memberships[] = ['contactGroupMembership' => ['contactGroupResourceName' => $resource]];
            }
        }

        return $memberships;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws CalendarSyncException
     */
    private function sourcesOf(Account $account, string $href): array
    {
        $person  = $this->client->get($account, '/' . $href, ['personFields' => 'metadata'], 'reading a contact');
        $sources = $person['metadata']['sources'] ?? [];

        return array_values(array_filter(is_array($sources) ? $sources : [], static fn (mixed $s): bool => is_array($s) && 'CONTACT' === ($s['type'] ?? null)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(?string $resource): array
    {
        $decoded = null === $resource ? null : json_decode($resource, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function accountOf(AddressBook $book): Account
    {
        return $book->account ?? throw new CalendarSyncPermanentException(sprintf('Address book #%d is not attached to a Google account.', (int) $book->id));
    }
}
