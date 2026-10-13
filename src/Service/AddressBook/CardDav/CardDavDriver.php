<?php

declare(strict_types=1);

namespace App\Service\AddressBook\CardDav;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\VerifiableDriverInterface;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use App\Entity\Integration\Integration;
use App\Service\Calendar\Sync\CalDav\CalDavClient;
use App\Service\Calendar\Sync\CalDav\CalDavDiscovery;
use App\Service\Calendar\Sync\CalDav\DavResource;
use App\Service\Calendar\Sync\CalDav\DavService;
use App\Service\Calendar\Sync\CalDav\MultiStatusParser;
use Psr\Log\LoggerInterface;

/**
 * Address books on any RFC 6352 server, over the same transport the calendars
 * use.
 *
 * CalDavCalendarDriver's sibling, and deliberately the same shape: the client,
 * the discovery and the multistatus parser are shared (see DavService for how
 * discovery tells the two apart), so the SSRF checks, the hand-followed
 * redirects and the failure classification are the ones calendars already
 * rely on. What is here is only what an address book does differently — the
 * `addressbook` resourcetype, `address-data` instead of `calendar-data`, and a
 * vCard rather than an iCalendar body.
 *
 * ── Reading changes ───────────────────────────────────────────────────────
 *
 * The calendar driver's rule, for the calendar driver's reason: sync-collection
 * when the server advertises it, because it is the only way to hear about a
 * deletion incrementally; otherwise getctag, and a moved ctag surrenders the
 * token so the next pull is a full listing that *can* express deletions. See
 * CalDavCalendarDriver's class docblock for the long version. The failures are
 * CalendarSync* exceptions on purpose — their classes decide retry behaviour,
 * and that decision is the same for a contacts server.
 *
 * ── Writing ───────────────────────────────────────────────────────────────
 *
 * PUT with If-None-Match: * for a new card and If-Match for an edit, DELETE
 * with If-Match. A 412 means somebody else changed the card first; it is
 * raised as a resync, and the sync service lets the server's version win.
 */
final readonly class CardDavDriver implements VerifiableDriverInterface
{
    /** Everything worth knowing about an address-book collection, in one PROPFIND. */
    private const string COLLECTION_PROPS = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-privilege-set/>
            <d:supported-report-set/>
            <d:sync-token/>
            <cs:getctag/>
          </d:prop>
        </d:propfind>
        XML;

    /** See CalDavCalendarDriver::MAX_SYNC_PAGES. */
    private const int MAX_SYNC_PAGES = 20;

    public function __construct(
        private CalDavClient      $client,
        private CalDavDiscovery   $discovery,
        private MultiStatusParser $parser,
        private LoggerInterface   $logger,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return Provider::CardDav === $provider;
    }

    /**
     * Prove the credentials by finding the address books behind them — an
     * address that authenticates but serves no CardDAV is not a working
     * connection, and the connect form is the place to say so.
     *
     * @throws IntegrationException
     */
    public function verify(Integration $integration): void
    {
        try {
            $this->discovery->endpointFor($integration, DavService::AddressBook);
        } catch (CalendarSyncException $e) {
            throw new IntegrationException($e->getMessage(), $e->getStatus(), $e);
        }
    }

    /**
     * @return list<RemoteAddressBook>
     *
     * @throws CalendarSyncException
     */
    public function discover(Integration $integration): array
    {
        $endpoint = $this->discovery->endpointFor($integration, DavService::AddressBook);
        $url      = $endpoint->collection ?? (string) $endpoint->home;

        $response = $this->client->request($integration, 'PROPFIND', $url, [
            'headers' => [
                'Depth'        => true === $endpoint->isSingleCollection() ? '0' : '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::COLLECTION_PROPS,
        ]);

        $books = [];

        foreach ($this->parser->parse($response['body']) as $resource) {
            if (false === $resource->isAddressBookCollection()) {
                continue;
            }

            $href = $this->client->absolutise($resource->href, $url);

            $books[] = new RemoteAddressBook(
                remoteId:   $href,
                name:       $this->nameOf($resource, $href),
                isReadOnly: $resource->isReadOnly(),
            );
        }

        return $books;
    }

    /**
     * @throws CalendarSyncException
     */
    public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet
    {
        $integration = $book->integration;
        $collection  = $book->remoteId;
        $probe       = $this->probeCollection($integration, $collection);

        if (true === $probe->contains('supported-report-set', 'sync-collection')) {
            return $this->pullBySyncCollection($integration, $collection, $syncToken);
        }

        return $this->pullByCtag($integration, $collection, $probe->prop('getctag'), $syncToken);
    }

    /**
     * Send a card, answering with its href and the etag the server gave it.
     *
     * @return array{href: string, etag: string|null}
     *
     * @throws CalendarSyncException
     */
    public function put(AddressBook $book, ContactCard $card): array
    {
        $isCreate = null === $card->href;
        $href     = $card->href ?? $this->mintHref($book, $card);
        $headers  = ['Content-Type' => 'text/vcard; charset=utf-8'];

        if (true === $isCreate) {
            $headers['If-None-Match'] = '*';
        } elseif (null !== $card->etag) {
            $headers['If-Match'] = $card->etag;
        }

        $response = $this->client->request($book->integration, 'PUT', $href, [
            'headers' => $headers,
            'body'    => $card->vcard,
        ], [412]);

        if (412 === $response['status']) {
            throw new CalendarResyncRequiredException(
                'The contact changed on the server before our copy could be saved, so the address book is being read again.',
                412,
            );
        }

        return ['href' => $href, 'etag' => $this->client->etagAfterWrite($book->integration, $href, $response['headers'])];
    }

    /**
     * Idempotent: a card already gone is a card successfully deleted.
     *
     * @throws CalendarSyncException
     */
    public function delete(AddressBook $book, ContactCard $card): void
    {
        if (null === $card->href) {
            // Never reached the server, so there is nothing to tell it — and a
            // DELETE on the collection URL would delete the address book.
            return;
        }

        $headers  = null === $card->etag ? [] : ['If-Match' => $card->etag];
        $response = $this->client->request($book->integration, 'DELETE', $card->href, [
            'headers' => $headers,
        ], [404, 410, 412]);

        if (412 === $response['status']) {
            throw new CalendarResyncRequiredException(
                'The contact changed on the server before it could be deleted, so the address book is being read again.',
                412,
            );
        }
    }

    // ── Pulling ───────────────────────────────────────────────────────────────

    /**
     * @throws CalendarSyncException
     */
    private function pullBySyncCollection(Integration $integration, string $collection, ?string $syncToken): AddressBookChangeSet
    {
        $cards = [];
        $token = $syncToken;

        for ($page = 0; $page < self::MAX_SYNC_PAGES; ++$page) {
            $response = $this->client->request($integration, 'REPORT', $collection, [
                'headers' => [
                    'Depth'        => '1',
                    'Content-Type' => 'application/xml; charset=utf-8',
                ],
                'body' => $this->syncReportBody($token),
            ]);

            $resources = $this->parser->parse($response['body']);
            $nextToken = $this->parser->syncToken($response['body']);

            foreach ($this->toRemoteCards($integration, $collection, $resources) as $card) {
                $cards[] = $card;
            }

            if (false === $this->isTruncated($resources) || null === $nextToken || $nextToken === $token) {
                return AddressBookChangeSet::of($cards, $nextToken ?? $token, null === $syncToken);
            }

            $token = $nextToken;
        }

        $this->logger->warning('CardDav: stopped following a truncated sync report at the page cap', [
            'collection' => $collection,
            'pages'      => self::MAX_SYNC_PAGES,
        ]);

        // Not a full listing even when it started as one: pages are missing,
        // and treating what arrived as everything would delete the rest.
        return AddressBookChangeSet::of($cards, $token, false);
    }

    /**
     * @throws CalendarSyncException
     */
    private function pullByCtag(Integration $integration, string $collection, ?string $ctag, ?string $syncToken): AddressBookChangeSet
    {
        if (null !== $syncToken) {
            if (null !== $ctag && $ctag === $syncToken) {
                return AddressBookChangeSet::unchanged($syncToken);
            }

            return AddressBookChangeSet::resyncRequired();
        }

        $response = $this->client->request($integration, 'REPORT', $collection, [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => $this->queryReportBody(),
        ]);

        $cards = $this->toRemoteCards($integration, $collection, $this->parser->parse($response['body']));

        // The ctag read before the listing — see CalDavCalendarDriver::pullByCtag().
        return AddressBookChangeSet::of($cards, $ctag, true);
    }

    /**
     * @param list<DavResource> $resources
     *
     * @return list<RemoteCard>
     *
     * @throws CalendarSyncException
     */
    private function toRemoteCards(Integration $integration, string $collection, array $resources): array
    {
        $cards   = [];
        $pending = [];

        foreach ($resources as $resource) {
            $href = $this->client->absolutise($resource->href, $collection);

            if (rtrim($href, '/') === rtrim($collection, '/')) {
                continue;
            }

            if (404 === $resource->status || 410 === $resource->status) {
                $cards[] = RemoteCard::deleted($href);

                continue;
            }

            $data = $resource->prop('address-data');

            if (null === $data) {
                $pending[] = $href;

                continue;
            }

            $cards[] = RemoteCard::present($href, $resource->prop('getetag'), $data);
        }

        foreach ($this->multiget($integration, $collection, $pending) as $card) {
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * The bodies a sync report left out, in one addressbook-multiget.
     *
     * @param list<string> $hrefs
     *
     * @return list<RemoteCard>
     *
     * @throws CalendarSyncException
     */
    private function multiget(Integration $integration, string $collection, array $hrefs): array
    {
        if ([] === $hrefs) {
            return [];
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/><card:address-data/></d:prop>';

        foreach ($hrefs as $href) {
            $body .= sprintf('<d:href>%s</d:href>', htmlspecialchars($href, ENT_XML1));
        }

        $response = $this->client->request($integration, 'REPORT', $collection, [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => $body . '</card:addressbook-multiget>',
        ]);

        $cards = [];

        foreach ($this->parser->parse($response['body']) as $resource) {
            $data = $resource->prop('address-data');

            if (null !== $data) {
                $cards[] = RemoteCard::present(
                    $this->client->absolutise($resource->href, $collection),
                    $resource->prop('getetag'),
                    $data,
                );
            }
        }

        return $cards;
    }

    /**
     * @param list<DavResource> $resources
     */
    private function isTruncated(array $resources): bool
    {
        foreach ($resources as $resource) {
            if (507 === $resource->status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws CalendarSyncException
     */
    private function probeCollection(Integration $integration, string $collection): DavResource
    {
        $response = $this->client->request($integration, 'PROPFIND', $collection, [
            'headers' => [
                'Depth'        => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::COLLECTION_PROPS,
        ]);

        $resources = $this->parser->parse($response['body']);

        if ([] === $resources) {
            throw new CalendarSyncPermanentException(
                'The contacts server did not describe this address book. It may have been deleted or renamed there.',
            );
        }

        return $resources[0];
    }

    // ── Request bodies ────────────────────────────────────────────────────────

    private function syncReportBody(?string $token): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:sync-collection xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<d:sync-token>%s</d:sync-token>'
            . '<d:sync-level>1</d:sync-level>'
            . '<d:prop><d:getetag/><card:address-data/></d:prop>'
            . '</d:sync-collection>',
            htmlspecialchars($token ?? '', ENT_XML1),
        );
    }

    /**
     * Every card, unfiltered — a filter would make the listing a lie the sync
     * service then acts on by deleting what it did not mention.
     */
    private function queryReportBody(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/><card:address-data/></d:prop>'
            . '</card:addressbook-query>';
    }

    // ── One collection ────────────────────────────────────────────────────────

    private function nameOf(DavResource $resource, string $href): string
    {
        $name = $resource->prop('displayname');

        if (null !== $name) {
            return $name;
        }

        $segment = basename(rtrim((string) parse_url($href, PHP_URL_PATH), '/'));

        return '' === $segment ? 'Contacts' : rawurldecode($segment);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * Where a card created here is PUT: named after its UID, the convention
     * every CardDAV client follows (RFC 6352 §6.3.2.3).
     */
    private function mintHref(AddressBook $book, ContactCard $card): string
    {
        return sprintf('%s/%s.vcf', rtrim($book->remoteId, '/'), rawurlencode($card->uid));
    }
}
