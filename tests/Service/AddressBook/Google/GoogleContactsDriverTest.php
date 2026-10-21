<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\Google;

use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\AddressBook\Google\GoogleContactMapper;
use App\Service\AddressBook\Google\GoogleContactsDriver;
use App\Service\AddressBook\Google\GooglePeopleClient;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Contacts\ParsedContact;
use App\Domain\DTO\AddressBook\RemoteCard;

/**
 * A Google account's contacts through the People API, against scripted
 * answers in the shapes its reference documents.
 *
 * The claims: a full read follows the pages, carries the sync token back and
 * says it is a full listing; a delta's tombstones are deletions; groups are
 * tags; the etag kept is the CONTACT source's, which is what an update is
 * checked against; an update names only the fields plMail owns and sends the
 * etag and sources back; a stale etag is a conflict and an expired sync token
 * a resync; a missing scope says what to do.
 */
final class GoogleContactsDriverTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string, headers: list<string>}> */
    private array $requests = [];

    public function testItHandlesGoogleAccountsAndOffersOneBook(): void
    {
        $driver = $this->driver([]);

        self::assertTrue($driver->handles(AddressBookSource::ofAccount($this->account())));
        self::assertFalse($driver->handles(AddressBookSource::ofAccount($this->account(MailProvider::Microsoft))));

        $books = $driver->discover(AddressBookSource::ofAccount($this->account()));

        self::assertCount(1, $books);
        self::assertSame(GoogleContactsDriver::BOOK, $books[0]->remoteId);
        self::assertFalse($books[0]->isReadOnly);
        self::assertSame([], $this->requests, 'nothing to ask Google for that');
    }

    public function testAFullReadFollowsThePagesAndIsAFullListing(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json(['connections' => [$this->person('c1', 'Ada Lovelace', 'ada@example.com', ['contactGroups/friends'])], 'nextPageToken' => 'p2']),
            $this->json(['connections' => [$this->person('c2', 'Grace Hopper', 'grace@navy.example')], 'nextSyncToken' => 'sync-1']),
        ]);

        $changes = $driver->pull($this->book(), null);

        self::assertTrue($changes->isFullListing);
        self::assertSame('sync-1', $changes->syncToken);
        self::assertCount(2, $changes->cards);
        self::assertSame('people/c1', $changes->cards[0]->href);
        self::assertSame('"src-c1"', $changes->cards[0]->etag, 'the CONTACT source etag, which updateContact checks');
        $ada = $changes->cards[0]->parsed;
        self::assertNotNull($ada);
        self::assertSame('Ada Lovelace', $ada->displayName);
        self::assertSame([['email' => 'ada@example.com', 'label' => 'home', 'primary' => true]], $ada->emails);
        self::assertSame(['Friends'], $ada->tags, 'a user group is a tag; myContacts is not');
        self::assertStringContainsString('requestSyncToken=true', $this->requests[1]['url']);
        self::assertStringContainsString('pageToken=p2', $this->requests[2]['url']);
    }

    public function testADeltaReportsDeletionsAndIsNotAFullListing(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json([
                'connections'   => [['resourceName' => 'people/c9', 'metadata' => ['deleted' => true]]],
                'nextSyncToken' => 'sync-2',
            ]),
        ]);

        $changes = $driver->pull($this->book(), 'sync-1');

        self::assertFalse($changes->isFullListing);
        self::assertTrue($changes->cards[0]->isDeleted());
        self::assertSame('people/c9', $changes->cards[0]->href);
        self::assertStringContainsString('syncToken=sync-1', $this->requests[1]['url']);
    }

    public function testAnExpiredSyncTokenAsksForAResync(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json(['error' => [
                'code'    => 400,
                'status'  => 'FAILED_PRECONDITION',
                'message' => 'Sync token is expired. Clear local cache and retry call without the sync token.',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'EXPIRED_SYNC_TOKEN']],
            ]], 400),
        ]);

        try {
            $driver->pull($this->book(), 'old');
            self::fail('an expired token must ask for a resync');
        } catch (CalendarResyncRequiredException $e) {
            self::assertSame(410, $e->getStatus(), 'a dead position, not a conflict');
        }
    }

    public function testANewContactIsCreatedInMyContactsWithItsTags(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json(['resourceName' => 'contactGroups/new1', 'name' => 'Work']),
            $this->json($this->person('c7', 'Grace Hopper', 'grace@navy.example')),
        ]);

        $contact       = $this->contact('Grace Hopper', 'grace@navy.example');
        $contact->tags = ['Friends', 'Work'];

        $written = $driver->put($this->book(), $contact);

        self::assertSame('people/c7', $written->href);
        self::assertSame('"src-c7"', $written->etag);
        self::assertSame('POST', $this->requests[1]['method'], 'a group for the tag Google has none for');
        self::assertStringEndsWith('/contactGroups', $this->requests[1]['url']);
        self::assertStringEndsWith('/people:createContact?personFields=' . rawurlencode(GoogleContactMapper::READ_FIELDS), $this->requests[2]['url']);

        $body = json_decode($this->requests[2]['body'], true);
        self::assertSame(
            ['contactGroups/myContacts', 'contactGroups/friends', 'contactGroups/new1'],
            array_map(static fn (array $m): string => $m['contactGroupMembership']['contactGroupResourceName'], $body['memberships']),
        );
        self::assertSame([['value' => 'grace@navy.example', 'type' => 'other']], $body['emailAddresses']);
        self::assertNotNull($written->resource, 'the sources kept for the next update');
    }

    public function testAnUpdateNamesOnlyPlMailsFieldsAndCarriesTheEtagAndSources(): void
    {
        $driver = $this->driver([$this->groupList(), $this->json($this->person('c1', 'Ada King', 'ada@example.com'))]);

        $contact                 = $this->contact('Ada King', 'ada@example.com');
        $contact->href           = 'people/c1';
        $contact->etag           = '"src-c1"';
        $contact->remoteResource = json_encode([
            'sources'     => [['type' => 'CONTACT', 'id' => 'abc', 'etag' => '"src-c1"']],
            'memberships' => [['contactGroupMembership' => ['contactGroupResourceName' => 'contactGroups/starred']]],
        ]);
        ContactPhone::create($contact, '+44 20 7946 0000', 'mobile');

        $driver->put($this->book(), $contact);

        self::assertSame('PATCH', $this->requests[1]['method']);
        self::assertStringContainsString('/people/c1:updateContact', $this->requests[1]['url']);
        self::assertStringContainsString('updatePersonFields=' . rawurlencode(GoogleContactMapper::UPDATE_FIELDS), $this->requests[1]['url']);

        $body = json_decode($this->requests[1]['body'], true);
        self::assertSame('"src-c1"', $body['etag']);
        self::assertSame([['type' => 'CONTACT', 'id' => 'abc', 'etag' => '"src-c1"']], $body['metadata']['sources']);
        self::assertSame('contactGroups/starred', $body['memberships'][0]['contactGroupMembership']['contactGroupResourceName'], 'Starred is kept, not taken for a tag');
        self::assertSame([['value' => '+44 20 7946 0000', 'type' => 'mobile']], $body['phoneNumbers']);
        self::assertSame([], $body['biographies'], 'an empty field is sent empty, so a value removed here is removed there');
        self::assertSame([], $body['birthdays'], 'a modelled field goes out even when empty');
        self::assertStringNotContainsString('occupations', GoogleContactMapper::UPDATE_FIELDS, 'what plMail does not model is Google\'s to keep');
        self::assertArrayNotHasKey('occupations', $body);
    }

    public function testAPhotoChangedHereIsSentAfterTheContactAndItsEtagIsKept(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json($this->person('c1', 'Ada King', 'ada@example.com')),
            $this->json(['person' => ['resourceName' => 'people/c1', 'metadata' => ['sources' => [['type' => 'CONTACT', 'id' => 'c1', 'etag' => '"src-after-photo"']]]]]),
        ]);

        $contact             = $this->contact('Ada King', 'ada@example.com');
        $contact->href           = 'people/c1';
        $contact->etag           = '"src-c1"';
        $contact->photoDirty     = true;
        $contact->remoteResource = $this->sources();
        $contact->photoPath      = $this->storedPhoto('jpeg bytes');

        $written = $driver->put($this->book(), $contact);

        self::assertSame('PATCH', $this->requests[2]['method']);
        self::assertStringContainsString('/people/c1:updateContactPhoto', $this->requests[2]['url']);
        self::assertSame(base64_encode('jpeg bytes'), json_decode($this->requests[2]['body'], true)['photoBytes']);
        self::assertSame('"src-after-photo"', $written->etag, 'the photo changed the etag; the next update must carry the new one');
    }

    public function testAPhotoRemovedHereIsRemovedAtGoogle(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json($this->person('c1', 'Ada King', 'ada@example.com')),
            $this->json(['person' => ['resourceName' => 'people/c1']]),
        ]);

        $contact             = $this->contact('Ada King', 'ada@example.com');
        $contact->href           = 'people/c1';
        $contact->photoDirty     = true;
        $contact->remoteResource = $this->sources();

        $driver->put($this->book(), $contact);

        self::assertSame('DELETE', $this->requests[2]['method']);
        self::assertStringContainsString('/people/c1:deleteContactPhoto', $this->requests[2]['url']);
    }

    public function testAnUnchangedPhotoIsNotSent(): void
    {
        $driver = $this->driver([$this->groupList(), $this->json($this->person('c1', 'Ada King', 'ada@example.com'))]);

        $contact            = $this->contact('Ada King', 'ada@example.com');
        $contact->href           = 'people/c1';
        $contact->photoPath      = $this->storedPhoto('jpeg bytes');
        $contact->remoteResource = $this->sources();

        $driver->put($this->book(), $contact);

        self::assertCount(2, $this->requests);
    }

    public function testAPhotoIsReadFromGooglesImageHostAtTheSizeKeptAndWithoutTheToken(): void
    {
        $driver = $this->driver([new MockResponse('png bytes', ['response_headers' => ['content-type' => 'image/png']])]);
        $parsed = new ParsedContact();

        $parsed->photoVersion = 'https://lh3.googleusercontent.com/abc=s100';

        $photo = $driver->photo($this->book(), RemoteCard::parsed('people/c1', null, $parsed));

        self::assertSame('png bytes', $photo?->bytes);
        self::assertSame('image/png', $photo->mime);
        self::assertSame('https://lh3.googleusercontent.com/abc=s512', $this->requests[0]['url']);
        self::assertSame([], array_filter($this->requests[0]['headers'] ?? [], static fn (string $h): bool => str_starts_with(strtolower($h), 'authorization')));
    }

    public function testAPhotoAddressAnywhereElseIsRefusedUnasked(): void
    {
        $driver = $this->driver([]);
        $parsed = new ParsedContact();

        $parsed->photoVersion = 'https://attacker.example/x.jpg';

        try {
            $driver->photo($this->book(), RemoteCard::parsed('people/c1', null, $parsed));
            self::fail('expected a refusal');
        } catch (CalendarSyncPermanentException) {
        }

        self::assertSame([], $this->requests);
    }

    public function testAStaleEtagIsAConflict(): void
    {
        $driver = $this->driver([
            $this->groupList(),
            $this->json(['error' => ['code' => 400, 'status' => 'FAILED_PRECONDITION', 'message' => 'Request person.etag is different than the current person.etag.', 'details' => [['reason' => 'failedPrecondition']]]], 400),
        ]);

        $contact                 = $this->contact('Ada', 'ada@example.com');
        $contact->href           = 'people/c1';
        $contact->etag           = '"old"';
        $contact->remoteResource = json_encode(['sources' => [['type' => 'CONTACT', 'id' => 'abc', 'etag' => '"old"']], 'memberships' => []]);

        try {
            $driver->put($this->book(), $contact);
            self::fail('a stale etag must be a conflict');
        } catch (CalendarResyncRequiredException $e) {
            self::assertSame(412, $e->getStatus(), 'what AddressBookSync reads as "the server wins"');
        }
    }

    public function testAMissingScopeSaysWhatToDo(): void
    {
        $driver = $this->driver([
            $this->json(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Request had insufficient authentication scopes.', 'details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']]]], 403),
        ]);

        try {
            $driver->pull($this->book(), null);
            self::fail('a missing scope is permanent');
        } catch (CalendarSyncPermanentException $e) {
            self::assertSame(GooglePeopleClient::RECONNECT_HINT, $e->getMessage());
        }
    }

    /**
     * The People API not enabled in the installation's Cloud project, in both
     * of Google's spellings. Not the reconnect hint: reconnecting cannot fix
     * it, and the line has to say who can.
     */
    public function testAPeopleApiThatIsNotEnabledSaysTheAdministratorHasToEnableIt(): void
    {
        $disabled = 'People API has not been used in project 1234 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/people.googleapis.com/overview?project=1234 then retry.';

        foreach ([
            ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => $disabled, 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED', 'domain' => 'googleapis.com']]]],
            ['error' => ['code' => 403, 'message' => $disabled, 'errors' => [['reason' => 'accessNotConfigured', 'domain' => 'usageLimits', 'message' => $disabled]]]],
        ] as $answer) {
            $driver = $this->driver([$this->json($answer, 403)]);

            try {
                $driver->pull($this->book(), null);
                self::fail('a disabled API is permanent');
            } catch (CalendarSyncPermanentException $e) {
                self::assertSame(GooglePeopleClient::API_DISABLED_HINT, $e->getMessage());
                self::assertStringNotContainsString('1234', $e->getMessage(), 'no project number or console link on a user\'s screen');
            }
        }
    }

    public function testAQuotaIsThrottling(): void
    {
        $driver = $this->driver([$this->json(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']], 429, ['retry-after' => '30'])]);

        $this->expectException(CalendarSyncThrottledException::class);

        $driver->pull($this->book(), null);
    }

    public function testDeletingAContactAlreadyGoneSucceeds(): void
    {
        $driver = $this->driver([$this->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404)]);

        $driver->delete($this->book(), 'people/c1', '"1"');

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertStringEndsWith('/people/c1:deleteContact', $this->requests[0]['url']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse> $responses
     */
    private function driver(array $responses): GoogleContactsDriver
    {
        $this->requests = [];
        $http           = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? ''), 'headers' => array_values(array_map(strval(...), $options['headers'] ?? []))];

            return array_shift($responses) ?? self::fail('an unscripted request: ' . $method . ' ' . $url);
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        return new GoogleContactsDriver(new GooglePeopleClient($http, $tokens), new GoogleContactMapper(), new ContactPhotoStore(sys_get_temp_dir()));
    }

    private function groupList(): MockResponse
    {
        return $this->json(['contactGroups' => [
            ['resourceName' => 'contactGroups/myContacts', 'name' => 'myContacts', 'groupType' => 'SYSTEM_CONTACT_GROUP'],
            ['resourceName' => 'contactGroups/friends', 'name' => 'Friends', 'groupType' => 'USER_CONTACT_GROUP'],
        ]]);
    }

    /**
     * @param list<string> $groups
     *
     * @return array<string, mixed>
     */
    private function person(string $id, string $name, string $email, array $groups = []): array
    {
        return [
            'resourceName'   => 'people/' . $id,
            'etag'           => '"person-' . $id . '"',
            'metadata'       => ['sources' => [['type' => 'CONTACT', 'id' => $id, 'etag' => '"src-' . $id . '"']]],
            'names'          => [['displayName' => $name, 'metadata' => ['source' => ['type' => 'CONTACT']]]],
            'emailAddresses' => [['value' => $email, 'type' => 'home', 'metadata' => ['primary' => true]]],
            'memberships'    => array_map(
                static fn (string $g): array => ['contactGroupMembership' => ['contactGroupResourceName' => $g]],
                ['contactGroups/myContacts', ...$groups],
            ),
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function json(array $body, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => array_merge(['content-type' => 'application/json'], $headers),
        ]);
    }

    private function account(MailProvider $provider = MailProvider::Google): Account
    {
        $account                = new Account();
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = $provider->value;
        $account->usr           = new User();

        return $account;
    }

    private function book(): AddressBook
    {
        $account = $this->account();

        return new AddressBook($account->usr ?? new User(), $account, GoogleContactsDriver::BOOK, GoogleContactsDriver::BOOK_NAME);
    }

    /** What the last read kept, so a write needs no request for the sources. */
    private function sources(): string
    {
        return (string) json_encode(['sources' => [['type' => 'CONTACT', 'id' => 'c1', 'etag' => '"src-c1"']]]);
    }

    /** A photo where ContactPhotoStore(sys_get_temp_dir()) reads it; the path Contact::$photoPath holds. */
    private function storedPhoto(string $bytes): string
    {
        $dir = sys_get_temp_dir() . '/var/uploads/contacts/google-test';

        if (false === is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($dir . '/photo.jpg', $bytes);

        return 'contacts/google-test/photo.jpg';
    }

    private function contact(string $name, string $email): Contact
    {
        $contact = Contact::create(new User(), $name);
        ContactEmail::create($contact, $email);

        return $contact;
    }
}
