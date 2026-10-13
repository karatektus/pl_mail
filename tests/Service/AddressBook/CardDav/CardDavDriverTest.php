<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\CardDav;

use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\IntegrationException;
use App\Entity\AddressBook\ContactCard;
use App\Tests\Service\Calendar\Sync\CalDav\CalDavFixture;
use PHPUnit\Framework\TestCase;

/**
 * The CardDAV driver against a scripted server: discovery, both ways of
 * reading changes, and conditional writes.
 *
 * The claims mirror the calendar driver's, because the failure modes do. A
 * listing asked without a token is complete and one asked with a token is not;
 * a moved ctag gives the token up rather than returning a listing the sync
 * service would mistake for a delta; and every write is conditional, so an
 * edit made on a phone in the meantime is never silently overwritten.
 */
final class CardDavDriverTest extends TestCase
{
    private const string BOOK = 'https://dav.example.com/dav/addressbooks/alice/contacts/';

    // ── Discovery ─────────────────────────────────────────────────────────────

    public function testDiscoveryListsTheAddressBooksAndNothingElseUnderTheHome(): void
    {
        $fixture = new CardDavFixture(
            CalDavFixture::multistatus($this->homeOnPrincipal()),
            CalDavFixture::multistatus(<<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
                  <d:response>
                    <d:href>/dav/addressbooks/alice/</d:href>
                    <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
                  </d:response>
                  <d:response>
                    <d:href>/dav/addressbooks/alice/contacts/</d:href>
                    <d:propstat><d:prop>
                      <d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>
                      <d:displayname>Contacts</d:displayname>
                      <d:current-user-privilege-set><d:privilege><d:read/></d:privilege><d:privilege><d:write-content/></d:privilege></d:current-user-privilege-set>
                    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
                  </d:response>
                  <d:response>
                    <d:href>/dav/addressbooks/alice/z-server-generated--system/</d:href>
                    <d:propstat><d:prop>
                      <d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>
                      <d:current-user-privilege-set><d:privilege><d:read/></d:privilege></d:current-user-privilege-set>
                    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
                  </d:response>
                </d:multistatus>
                XML),
        );

        $books = $fixture->driver->discover(CardDavFixture::integration('https://dav.example.com/dav'));

        self::assertCount(2, $books, 'the home itself is not an address book');
        self::assertSame(self::BOOK, $books[0]->remoteId);
        self::assertSame('Contacts', $books[0]->name);
        self::assertFalse($books[0]->isReadOnly);

        self::assertSame('z-server-generated--system', $books[1]->name, 'no displayname: the path segment');
        self::assertTrue($books[1]->isReadOnly, 'a privilege set with no write is read-only');

        self::assertSame('1', $fixture->header(1, 'Depth'), 'the home is listed one level deep');
    }

    public function testVerifyReportsARejectedPasswordAsAnIntegrationFailure(): void
    {
        $fixture = new CardDavFixture(CalDavFixture::status(401));

        try {
            $fixture->driver->verify(CardDavFixture::integration('https://dav.example.com/dav'));
            self::fail('a 401 must not verify');
        } catch (IntegrationException $e) {
            self::assertSame(401, $e->getStatus());
            self::assertStringContainsString('contacts server rejected the app password', $e->getMessage());
        }
    }

    public function testAnAddressInsideTheContainerNetworkIsRefusedBeforeAnyRequest(): void
    {
        $fixture = new CardDavFixture();

        $this->expectException(CalendarSyncPermanentException::class);

        try {
            $fixture->driver->discover(CardDavFixture::integration('https://10.0.0.5/dav'));
        } finally {
            self::assertSame(0, $fixture->requestCount());
        }
    }

    // ── sync-collection ───────────────────────────────────────────────────────

    public function testAnInitialSyncIsAFullListingAndFetchesMissingBodiesInOneMultiget(): void
    {
        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: true),
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/ada.vcf'   => ['etag' => '"1"', 'vcard' => CardDavFixture::vcard('ada', 'Ada', 'ada@example.com')],
                '/dav/addressbooks/alice/contacts/grace.vcf' => ['etag' => '"2"', 'vcard' => null],
            ], 'token-1'),
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/grace.vcf' => ['etag' => '"2"', 'vcard' => CardDavFixture::vcard('grace', 'Grace', 'grace@example.com')],
            ]),
        );

        $changes = $fixture->driver->pull(CardDavFixture::book(self::BOOK), null);

        self::assertTrue($changes->isFullListing);
        self::assertSame('token-1', $changes->syncToken);
        self::assertCount(2, $changes->cards);
        self::assertSame(self::BOOK . 'grace.vcf', $changes->cards[1]->href);
        self::assertStringContainsString('Grace', (string) $changes->cards[1]->vcard);

        self::assertStringContainsString('<d:sync-token></d:sync-token>', $fixture->body(1), 'an empty token asks for everything');
        self::assertStringContainsString('addressbook-multiget', $fixture->body(2));
        self::assertStringContainsString('grace.vcf', $fixture->body(2));
        self::assertStringNotContainsString('ada.vcf', $fixture->body(2), 'only the missing body is fetched');
    }

    public function testADeltaReportsDeletionsAndIsNotAFullListing(): void
    {
        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: true),
            CardDavFixture::report(['/dav/addressbooks/alice/contacts/gone.vcf' => null], 'token-2'),
        );

        $changes = $fixture->driver->pull(CardDavFixture::book(self::BOOK), 'token-1');

        self::assertFalse($changes->isFullListing, 'asked with a token: what is not mentioned is unchanged');
        self::assertSame('token-2', $changes->syncToken);
        self::assertTrue($changes->cards[0]->isDeleted());
        self::assertStringContainsString('<d:sync-token>token-1</d:sync-token>', $fixture->body(1));
    }

    public function testADeadSyncTokenAsksForAResync(): void
    {
        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: true),
            CalDavFixture::deadSyncToken(409),
        );

        $this->expectException(CalendarResyncRequiredException::class);

        $fixture->driver->pull(CardDavFixture::book(self::BOOK), 'expired');
    }

    // ── ctag fallback ─────────────────────────────────────────────────────────

    public function testAnUnmovedCtagCostsOneRequest(): void
    {
        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: false, ctag: 'ctag-7'),
        );

        $changes = $fixture->driver->pull(CardDavFixture::book(self::BOOK), 'ctag-7');

        self::assertSame([], $changes->cards);
        self::assertFalse($changes->resyncRequired);
        self::assertSame(1, $fixture->requestCount());
    }

    /**
     * A listing cannot express a deletion against a token, so a moved ctag
     * gives the token up and the next pull reads everything.
     */
    public function testAMovedCtagSurrendersTheToken(): void
    {
        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: false, ctag: 'ctag-8'),
        );

        self::assertTrue($fixture->driver->pull(CardDavFixture::book(self::BOOK), 'ctag-7')->resyncRequired);
    }

    public function testWithoutATokenTheFallbackListsEverything(): void
    {
        $fixture = new CardDavFixture(
            CardDavFixture::collection('/dav/addressbooks/alice/contacts/', syncCollection: false, ctag: 'ctag-8'),
            CardDavFixture::report([
                '/dav/addressbooks/alice/contacts/ada.vcf' => ['etag' => '"1"', 'vcard' => CardDavFixture::vcard('ada', 'Ada', 'ada@example.com')],
            ]),
        );

        $changes = $fixture->driver->pull(CardDavFixture::book(self::BOOK), null);

        self::assertTrue($changes->isFullListing);
        self::assertSame('ctag-8', $changes->syncToken, 'the ctag read before the listing');
        self::assertCount(1, $changes->cards);
        self::assertStringContainsString('addressbook-query', $fixture->body(1));
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    public function testANewCardIsPutUnderItsUidAndMayNotOverwrite(): void
    {
        $fixture = new CardDavFixture(CalDavFixture::status(201, ['etag' => '"new"']));

        $book       = CardDavFixture::book(self::BOOK);
        $card       = new ContactCard($book, 'uid with/slash');
        $card->vcard = CardDavFixture::vcard('uid with/slash', 'New', 'new@example.com');

        $written = $fixture->driver->put($book, $card);

        self::assertSame(self::BOOK . 'uid%20with%2Fslash.vcf', $written['href']);
        self::assertSame('"new"', $written['etag']);
        self::assertSame('PUT', $fixture->method(0));
        self::assertSame('*', $fixture->header(0, 'If-None-Match'));
        self::assertStringStartsWith('text/vcard', (string) $fixture->header(0, 'Content-Type'));
    }

    public function testAnEditIsConditionalOnTheEtagWeLastSaw(): void
    {
        $fixture = new CardDavFixture(CalDavFixture::status(412));

        $book        = CardDavFixture::book(self::BOOK);
        $card        = new ContactCard($book, 'ada');
        $card->href  = self::BOOK . 'ada.vcf';
        $card->etag  = '"1"';
        $card->vcard = CardDavFixture::vcard('ada', 'Ada', 'ada@example.com');

        try {
            $fixture->driver->put($book, $card);
            self::fail('a 412 means the card changed elsewhere and must not be ignored');
        } catch (CalendarResyncRequiredException) {
        }

        self::assertSame('"1"', $fixture->header(0, 'If-Match'));
        self::assertNull($fixture->header(0, 'If-None-Match'));
    }

    public function testDeletingACardAlreadyGoneSucceeds(): void
    {
        $fixture = new CardDavFixture(CalDavFixture::status(404));

        $book       = CardDavFixture::book(self::BOOK);
        $card       = new ContactCard($book, 'ada');
        $card->href = self::BOOK . 'ada.vcf';

        $fixture->driver->delete($book, $card);

        self::assertSame('DELETE', $fixture->method(0));
    }

    public function testACardTheServerNeverSawIsNotDeletedThere(): void
    {
        $fixture = new CardDavFixture();

        $fixture->driver->delete(CardDavFixture::book(self::BOOK), new ContactCard(CardDavFixture::book(self::BOOK), 'local'));

        self::assertSame(0, $fixture->requestCount(), 'a DELETE on the collection URL would delete the book');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function homeOnPrincipal(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
              <d:response>
                <d:href>/dav/</d:href>
                <d:propstat>
                  <d:prop>
                    <d:current-user-principal><d:href>/dav/principals/alice/</d:href></d:current-user-principal>
                    <card:addressbook-home-set><d:href>/dav/addressbooks/alice/</d:href></card:addressbook-home-set>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML;
    }
}
