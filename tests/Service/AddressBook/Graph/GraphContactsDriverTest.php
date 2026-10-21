<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\Graph;

use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\AddressBook\Graph\GraphContactMapper;
use App\Service\AddressBook\Graph\GraphContactsClient;
use App\Service\AddressBook\Graph\GraphContactsDriver;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Outlook contacts through Microsoft Graph, against scripted answers in the
 * shapes the Graph reference documents.
 *
 * The claims: every contact folder is a book; a fresh delta round is a full
 * listing and the delta link is the token; @removed is a deletion; a write
 * goes into the book's folder, an update carries If-Match, and a 412 is a
 * conflict; Outlook's fixed places are filled and no more; an expired delta
 * token is a resync; immutable ids are asked for every time.
 */
final class GraphContactsDriverTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string, headers: list<string>}> */
    private array $requests = [];

    public function testEveryContactFolderIsABook(): void
    {
        $driver = $this->driver([$this->json(['value' => [
            ['id' => 'F-default', 'displayName' => 'Contacts'],
            ['id' => 'F-work', 'displayName' => 'Work'],
        ]])]);

        $books = $driver->discover(AddressBookSource::ofAccount($this->account()));

        self::assertSame(['F-default', 'F-work'], array_map(static fn ($b): string => $b->remoteId, $books));
        self::assertSame('Contacts', $books[0]->name);
        self::assertStringEndsWith('/me/contactFolders?$select=id%2CdisplayName', $this->requests[0]['url']);
        self::assertContains('Prefer: IdType="ImmutableId"', $this->requests[0]['headers']);
        self::assertTrue($driver->handles(AddressBookSource::ofAccount($this->account())));
        self::assertFalse($driver->handles(AddressBookSource::ofAccount($this->account(MailProvider::Google))));
    }

    public function testAFreshRoundIsAFullListingAndEndsOnTheDeltaLink(): void
    {
        $driver = $this->driver([
            $this->json([
                'value'           => [$this->item('C1', 'Ada Lovelace', ['ada@example.com'])],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/contactFolders/F1/contacts/delta?$skiptoken=s1',
            ]),
            $this->json([
                'value'            => [$this->item('C2', 'Grace Hopper', ['grace@navy.example'])],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/contactFolders/F1/contacts/delta?$deltatoken=d1',
            ]),
        ]);

        $changes = $driver->pull($this->book(), null);

        self::assertTrue($changes->isFullListing);
        self::assertSame('https://graph.microsoft.com/v1.0/me/contactFolders/F1/contacts/delta?$deltatoken=d1', $changes->syncToken);
        self::assertSame(['C1', 'C2'], array_map(static fn ($c): string => $c->href, $changes->cards));
        self::assertSame('W/"etag-C1"', $changes->cards[0]->etag);

        $ada = $changes->cards[0]->parsed;
        self::assertNotNull($ada);
        self::assertSame('Ada Lovelace', $ada->displayName);
        self::assertSame([['email' => 'ada@example.com', 'label' => 'other', 'primary' => true]], $ada->emails);
        self::assertSame([['phone' => '+1 555 0100', 'label' => 'work'], ['phone' => '+1 555 0199', 'label' => 'mobile']], $ada->phones);
        self::assertSame('Seattle', $ada->addresses[0]['city'] ?? null);
        self::assertSame(['Friends'], $ada->tags, 'categories are tags');
        self::assertStringContainsString('/me/contactFolders/F1/contacts/delta', $this->requests[0]['url']);
        self::assertStringContainsString('skiptoken=s1', $this->requests[1]['url']);
    }

    public function testAResumedRoundReportsRemovalsAndIsNotAFullListing(): void
    {
        $driver = $this->driver([$this->json([
            'value'            => [['id' => 'C9', '@removed' => ['reason' => 'deleted']]],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/contactFolders/F1/contacts/delta?$deltatoken=d2',
        ])]);

        $changes = $driver->pull($this->book(), 'https://graph.microsoft.com/v1.0/me/contactFolders/F1/contacts/delta?$deltatoken=d1');

        self::assertFalse($changes->isFullListing);
        self::assertTrue($changes->cards[0]->isDeleted());
        self::assertSame('C9', $changes->cards[0]->href);
        self::assertStringContainsString('deltatoken=d1', $this->requests[0]['url'], 'the stored link, followed as it is');
    }

    public function testAnExpiredDeltaTokenAsksForAResync(): void
    {
        $driver = $this->driver([$this->json(['error' => ['code' => 'SyncStateNotFound', 'message' => 'The sync state is not found.']], 410)]);

        $this->expectException(CalendarResyncRequiredException::class);

        $driver->pull($this->book(), 'https://graph.microsoft.com/v1.0/me/contactFolders/F1/contacts/delta?$deltatoken=old');
    }

    public function testANewContactGoesIntoTheBooksFolder(): void
    {
        $driver = $this->driver([$this->json(['id' => 'C7', '@odata.etag' => 'W/"etag-C7"'], 201)]);

        $written = $driver->put($this->book(), $this->contact());

        self::assertSame('C7', $written->href);
        self::assertSame('W/"etag-C7"', $written->etag);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringEndsWith('/me/contactFolders/F1/contacts', $this->requests[0]['url']);
    }

    public function testOutlooksPlacesAreFilledAndNoMore(): void
    {
        $driver  = $this->driver([$this->json(['id' => 'C7'], 201)]);
        $contact = $this->contact();

        foreach (['b@example.com', 'c@example.com', 'd@example.com'] as $email) {
            ContactEmail::create($contact, $email);
        }

        ContactPhone::create($contact, '+1 1', 'work');
        ContactPhone::create($contact, '+1 2', 'work');
        ContactPhone::create($contact, '+1 3', 'work');
        ContactPhone::create($contact, '+1 4', 'mobile');

        $home         = ContactAddress::create($contact, 'home');
        $home->city   = 'London';

        $driver->put($this->book(), $contact);

        $body = json_decode($this->requests[0]['body'], true);

        self::assertCount(3, $body['emailAddresses'], 'Outlook holds three');
        self::assertSame('primary@example.com', $body['emailAddresses'][0]['address'], 'primary first');
        self::assertSame(['+1 1', '+1 2'], $body['businessPhones'], 'two business numbers');
        self::assertSame('+1 4', $body['mobilePhone']);
        self::assertSame(['city' => 'London'], $body['homeAddress']);
        self::assertSame([], $body['businessAddress'], 'sent empty, so an address removed here is removed there');
        self::assertSame('Ada Lovelace', $body['displayName'], 'always sent: Graph would regenerate it');
    }

    public function testAnUpdateIsConditionalAndAConflictIsA412(): void
    {
        $driver = $this->driver([$this->json(['error' => ['code' => 'ErrorIrresolvableConflict', 'message' => 'The change key passed in the request does not match the current change key for the item.']], 412)]);

        $contact       = $this->contact();
        $contact->href = 'C1';
        $contact->etag = 'W/"old"';

        try {
            $driver->put($this->book(), $contact);
            self::fail('a 412 must be a conflict');
        } catch (CalendarResyncRequiredException $e) {
            self::assertSame(412, $e->getStatus());
        }

        self::assertSame('PATCH', $this->requests[0]['method']);
        self::assertStringEndsWith('/me/contacts/C1', $this->requests[0]['url']);
        self::assertContains('If-Match: W/"old"', $this->requests[0]['headers']);
    }

    public function testARefusedAccessSaysWhatToDo(): void
    {
        $driver = $this->driver([$this->json(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.']], 403)]);

        try {
            $driver->discover(AddressBookSource::ofAccount($this->account()));
            self::fail('a refused scope is permanent');
        } catch (CalendarSyncPermanentException $e) {
            self::assertSame(GraphContactsClient::RECONNECT_HINT, $e->getMessage());
        }
    }

    public function testDeletingAContactAlreadyGoneSucceeds(): void
    {
        $driver = $this->driver([$this->json(['error' => ['code' => 'ErrorItemNotFound']], 404)]);

        $driver->delete($this->book(), 'C1', null);

        self::assertSame('DELETE', $this->requests[0]['method']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse> $responses
     */
    private function driver(array $responses): GraphContactsDriver
    {
        $this->requests = [];
        $http           = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [
                'method'  => $method,
                'url'     => $url,
                'body'    => (string) ($options['body'] ?? ''),
                'headers' => array_values(array_map('strval', $options['headers'] ?? [])),
            ];

            return array_shift($responses) ?? self::fail('an unscripted request: ' . $method . ' ' . $url);
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        return new GraphContactsDriver(new GraphContactsClient($http, $tokens), new GraphContactMapper());
    }

    /**
     * @param list<string> $emails
     *
     * @return array<string, mixed>
     */
    private function item(string $id, string $name, array $emails): array
    {
        return [
            'id'             => $id,
            '@odata.etag'    => 'W/"etag-' . $id . '"',
            'displayName'    => $name,
            'categories'     => ['Friends'],
            'emailAddresses' => array_map(static fn (string $e): array => ['name' => $name, 'address' => $e], $emails),
            'businessPhones' => ['+1 555 0100'],
            'homePhones'     => [],
            'mobilePhone'    => '+1 555 0199',
            'homeAddress'    => ['street' => '1 Main St', 'city' => 'Seattle'],
            'businessAddress' => [],
            'otherAddress'   => [],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function account(MailProvider $provider = MailProvider::Microsoft): Account
    {
        $account                = new Account();
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = $provider->value;
        $account->usr           = new User();

        return $account;
    }

    private function book(): AddressBook
    {
        return new AddressBook(new User(), $this->account(), 'F1', 'Contacts');
    }

    private function contact(): Contact
    {
        $contact = Contact::create(new User(), 'Ada Lovelace');
        $email   = ContactEmail::create($contact, 'primary@example.com');

        $email->isPrimary = true;

        return $contact;
    }
}
