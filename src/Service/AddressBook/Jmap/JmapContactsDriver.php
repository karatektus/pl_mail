<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Jmap;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Domain\DTO\AddressBook\RemoteContactWrite;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapResyncRequiredException;
use App\Domain\Exception\JmapThrottledException;
use App\Domain\Interface\AddressBookDriverInterface;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;

/**
 * A JMAP account's contacts (RFC 9610), behind the address-book driver
 * contract.
 *
 * Rides the JMAP mail account's own session, as the calendar driver does, and
 * hands the sync JSContact cards already parsed — the Google and Graph shape,
 * see RemoteCard. JsContactMapper is the translation, both ways.
 *
 * **One address book per JMAP AddressBook.** Its id is AddressBook::$remoteId.
 *
 * **Sync** is the account-wide ContactCard state, as calendars use the
 * CalendarEvent state: a null token is a full listing of the book, read with
 * ContactCard/query; after it, ContactCard/changes from the state stored as
 * the book's token, keeping what concerns this book. A card moved to another
 * book is a deletion here.
 *
 * **Photos** are a card's `media` entry of kind photo — a blob, or a data:
 * URI. Where it is travels in the card's resource string, so photo() can
 * fetch it without reading the card again. A photo changed here is uploaded
 * and set as the card's only media entry; one removed here clears it.
 *
 * Contact errors are reported as the calendar engine's exceptions, as the
 * Google and Graph contact drivers report them: AddressBookSync reads the same
 * three outcomes, permanent, throttled and worth another sweep.
 */
final readonly class JmapContactsDriver implements AddressBookDriverInterface
{
    /** How many changes one ContactCard/changes call may report. */
    private const int MAX_CHANGES = 500;

    public function __construct(
        private JmapClient        $jmap,
        private JsContactMapper   $mapper,
        private ContactPhotoStore $photos,
    ) {
    }

    /** Asked without the network, for the reason JmapCalendarSyncDriver::supports() gives. */
    public function handles(AddressBookSource $source): bool
    {
        return true === $source->account?->isJmap();
    }

    public function discover(AddressBookSource $source): array
    {
        if (null === $source->account || false === $source->account->isJmap()) {
            return [];
        }

        $account   = $source->account;
        $accountId = $this->contactsAccountId($account);

        if (null === $accountId) {
            return [];
        }

        $result = $this->call($account, [['AddressBook/get', ['accountId' => $accountId, 'ids' => null], 'b']], 'b', 'AddressBook/get');
        $books  = [];

        foreach (is_array($result['list'] ?? null) ? $result['list'] : [] as $book) {
            if (true === is_array($book) && true === is_string($book['id'] ?? null)) {
                $rights  = is_array($book['myRights'] ?? null) ? $book['myRights'] : [];
                $books[] = new RemoteAddressBook(
                    $book['id'],
                    is_string($book['name'] ?? null) && '' !== $book['name'] ? $book['name'] : 'Contacts',
                    false === ($rights['mayWrite'] ?? true),
                );
            }
        }

        return $books;
    }

    public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet
    {
        $account   = $this->accountOf($book);
        $accountId = $this->contactsAccountId($account) ?? throw new CalendarSyncPermanentException('The JMAP server no longer offers contacts for this account.');

        if (null === $syncToken) {
            $query = $this->call($account, [
                ['ContactCard/query', ['accountId' => $accountId, 'filter' => ['inAddressBook' => $book->remoteId]], 'q'],
            ], 'q', 'ContactCard/query');

            [$cards, $state] = $this->get($account, $accountId, array_values(array_filter(is_array($query['ids'] ?? null) ? $query['ids'] : [], 'is_string')));

            $state ??= (string) ($this->call($account, [
                ['ContactCard/get', ['accountId' => $accountId, 'ids' => [], 'properties' => ['id']], 'g'],
            ], 'g', 'ContactCard/get')['state'] ?? '');

            return AddressBookChangeSet::of(array_map($this->toRemote(...), $cards), '' === $state ? null : $state, true);
        }

        $changed   = [];
        $destroyed = [];
        $state     = $syncToken;

        do {
            try {
                $changes = $this->jmap->call($account, [JmapCapability::CONTACTS], [
                    ['ContactCard/changes', ['accountId' => $accountId, 'sinceState' => $state, 'maxChanges' => self::MAX_CHANGES], 'c'],
                ])->get('c', 'ContactCard/changes');
            } catch (JmapResyncRequiredException) {
                return AddressBookChangeSet::resyncRequired();
            } catch (JmapApiException $e) {
                throw $this->translate($e);
            }

            foreach (['created', 'updated'] as $kind) {
                foreach (is_array($changes[$kind] ?? null) ? $changes[$kind] : [] as $id) {
                    if (true === is_string($id)) {
                        $changed[$id] = true;
                    }
                }
            }

            foreach (is_array($changes['destroyed'] ?? null) ? $changes['destroyed'] : [] as $id) {
                if (true === is_string($id)) {
                    $destroyed[$id] = true;
                    unset($changed[$id]);
                }
            }

            $state = is_string($changes['newState'] ?? null) ? $changes['newState'] : $state;
        } while (true === ($changes['hasMoreChanges'] ?? false));

        $remote = [];

        if ([] !== $changed) {
            [$cards] = $this->get($account, $accountId, array_map(strval(...), array_keys($changed)));

            foreach ($cards as $card) {
                $remote[] = true === ($card['addressBookIds'][$book->remoteId] ?? false)
                    ? $this->toRemote($card)
                    : RemoteCard::deleted((string) $card['id']);
            }
        }

        foreach (array_keys($destroyed) as $id) {
            $remote[] = RemoteCard::deleted((string) $id);
        }

        return AddressBookChangeSet::of($remote, $state, false);
    }

    public function put(AddressBook $book, Contact $contact): RemoteContactWrite
    {
        $account   = $this->accountOf($book);
        $accountId = $this->contactsAccountId($account) ?? throw new CalendarSyncPermanentException('The JMAP server no longer offers contacts for this account.');
        $card      = $this->mapper->toCard($contact);

        if (true === $contact->photoDirty || (null === $contact->href && null !== $contact->photoPath)) {
            $photo         = $this->photos->read($contact->photoPath);
            $card['media'] = null === $photo ? null : ['photo' => [
                'kind'      => 'photo',
                'blobId'    => $this->upload($account, $accountId, $photo),
                'mediaType' => $photo->mime,
            ]];
        }

        if (null === $contact->href) {
            $create = array_filter($card, static fn (mixed $value): bool => null !== $value) + [
                '@type'          => 'Card',
                'version'        => '1.0',
                'uid'            => $contact->uid,
                'addressBookIds' => [$book->remoteId => true],
            ];

            $result = $this->call($account, [
                ['ContactCard/set', ['accountId' => $accountId, 'create' => ['c' => $create]], 's'],
            ], 's', 'ContactCard/set');

            $id = $result['created']['c']['id'] ?? null;

            if (false === is_string($id)) {
                throw $this->refusal('add', $result['notCreated']['c'] ?? null);
            }

            return new RemoteContactWrite(href: $id, etag: null);
        }

        $result = $this->call($account, [
            ['ContactCard/set', ['accountId' => $accountId, 'update' => [$contact->href => $card]], 's'],
        ], 's', 'ContactCard/set');

        $refused = $result['notUpdated'][$contact->href] ?? null;

        if (null !== $refused) {
            throw $this->refusal('save', $refused);
        }

        // No etag: JMAP versions a whole data type, as JmapCalendarSyncDriver
        // explains, and a null makes the next pull read the card again.
        return new RemoteContactWrite(href: $contact->href, etag: null);
    }

    public function photo(AddressBook $book, RemoteCard $card): ?ContactPhoto
    {
        $where = json_decode((string) $card->resource, true);

        if (false === is_array($where)) {
            return null;
        }

        $uri  = is_string($where['uri'] ?? null) ? $where['uri'] : null;
        $mime = is_string($where['mediaType'] ?? null) ? $where['mediaType'] : 'image/jpeg';

        if (null !== $uri && 1 === preg_match('#^data:([^;,]+)?(;base64)?,(.*)$#s', $uri, $m)) {
            $bytes = '' !== $m[2] ? base64_decode($m[3], true) : rawurldecode($m[3]);

            return false === $bytes ? null : new ContactPhoto($bytes, '' !== $m[1] ? $m[1] : $mime);
        }

        $blobId = is_string($where['blobId'] ?? null) ? $where['blobId'] : null;

        if (null === $blobId) {
            // A photo at an http(s) address is somebody else's server to be
            // asked, and fetching it would make this one a proxy for it.
            return null;
        }

        $account   = $this->accountOf($book);
        $accountId = $this->contactsAccountId($account);

        if (null === $accountId) {
            return null;
        }

        try {
            return new ContactPhoto($this->jmap->download($account, $accountId, $blobId, $mime, 'photo'), $mime);
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }
    }

    public function delete(AddressBook $book, ?string $href, ?string $etag): void
    {
        if (null === $href) {
            return;
        }

        $account   = $this->accountOf($book);
        $accountId = $this->contactsAccountId($account);

        if (null === $accountId) {
            return;
        }

        $result  = $this->call($account, [['ContactCard/set', ['accountId' => $accountId, 'destroy' => [$href]], 's']], 's', 'ContactCard/set');
        $refused = $result['notDestroyed'][$href] ?? null;

        if (null !== $refused && 'notFound' !== ($refused['type'] ?? null)) {
            throw $this->refusal('delete', $refused);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $card
     */
    private function toRemote(array $card): RemoteCard
    {
        $photo = $this->mapper->photoOf($card);

        return RemoteCard::parsed(
            (string) $card['id'],
            null,
            $this->mapper->toParsed($card),
            null === $photo ? null : (string) json_encode($photo, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function get(Account $account, string $accountId, array $ids): array
    {
        $cards = [];
        $state = null;

        try {
            $limit = max(1, $this->jmap->session($account)->coreLimit('maxObjectsInGet'));
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }

        foreach (array_chunk($ids, $limit) as $chunk) {
            $result = $this->call($account, [['ContactCard/get', ['accountId' => $accountId, 'ids' => $chunk], 'g']], 'g', 'ContactCard/get');

            $state ??= is_string($result['state'] ?? null) ? $result['state'] : null;

            foreach (is_array($result['list'] ?? null) ? $result['list'] : [] as $card) {
                if (true === is_array($card) && true === is_string($card['id'] ?? null)) {
                    $cards[] = $card;
                }
            }
        }

        return [$cards, $state];
    }

    private function upload(Account $account, string $accountId, ContactPhoto $photo): string
    {
        try {
            return $this->jmap->upload($account, $accountId, $photo->bytes, $photo->mime);
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $calls
     *
     * @return array<string, mixed>
     */
    private function call(Account $account, array $calls, string $callId, string $name): array
    {
        try {
            return $this->jmap->call($account, [JmapCapability::CONTACTS], $calls)->get($callId, $name);
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }
    }

    private function translate(JmapApiException $e): CalendarSyncException
    {
        if ($e instanceof JmapThrottledException) {
            return new CalendarSyncThrottledException($e->getMessage(), $e->getStatus(), $e->getRetryAfterSeconds(), $e);
        }

        if (true === in_array($e->getStatus(), [401, 403], true) || true === in_array($e->getType(), ['forbidden', 'accountNotFound', 'accountNotSupportedByMethod'], true)) {
            return new CalendarSyncPermanentException($e->getMessage(), $e->getStatus(), $e);
        }

        return new CalendarSyncException($e->getMessage(), $e->getStatus(), $e);
    }

    private function refusal(string $operation, mixed $error): CalendarSyncException
    {
        $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : 'unknown';

        return new CalendarSyncPermanentException(sprintf('The JMAP server would not %s the contact (%s).', $operation, $type));
    }

    private function contactsAccountId(Account $account): ?string
    {
        try {
            return $this->jmap->session($account)->accountFor(JmapCapability::CONTACTS);
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }
    }

    private function accountOf(AddressBook $book): Account
    {
        $account = $book->account;

        if (null === $account || false === $account->isJmap()) {
            throw new CalendarSyncPermanentException(sprintf('Address book #%d is not attached to a JMAP account.', (int) $book->id));
        }

        return $account;
    }
}
