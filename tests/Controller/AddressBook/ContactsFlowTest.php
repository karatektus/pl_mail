<?php

declare(strict_types=1);

namespace App\Tests\Controller\AddressBook;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Integration\Provider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\PushAddressBookMessage;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\User\UserRepository;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Service\Contacts\VCardMapper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Contacts end to end through the real controllers, forms and container: a
 * CardDAV server connected from Settings → Contacts, its address books ticked,
 * and its contacts created, edited and deleted on the contacts page beside
 * plMail's own.
 *
 * The outbound HTTP client is the only thing replaced — a scripted Nextcloud
 * on a `.test` host (RFC 2606), so the SSRF guard still runs on every URL and
 * nothing real is contacted. The transports are in-memory in this
 * environment, which is how the tests see that an edit was queued for the
 * server rather than sent inline: a push on export, a first sync on ingest.
 *
 * The claims worth naming:
 *   - a CardDAV connection discovers address books, and only address books —
 *     the same server's calendars are not offered;
 *   - an edit is saved here at once, marked pending and queued, and nothing is
 *     sent inline;
 *   - nobody reaches a contact that is not theirs, and nobody writes to a book
 *     the server made read-only.
 */
final class ContactsFlowTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string SERVER = 'https://cloud.carddav.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        // First, before anything else is fetched from the container: a
        // service already instantiated cannot be replaced (see
        // IcsControllerTest::signIn()), and the repository below builds the
        // entity manager and whatever it pulls in.
        $container->set('app.http_client.integration', new MockHttpClient($this->server(...)));

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->client->loginUser($user);
        $this->client->disableReboot();
        $this->connection->beginTransaction();

        $this->user = $this->em->find(User::class, $user->id);
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Settings → Contacts ───────────────────────────────────────────────────

    public function testTheSectionOffersToConnectAServer(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=contacts');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/settings/contacts/carddav/connect',
            $crawler->filter('[data-connect-carddav]')->attr('data-ui--modal-src-value'),
        );
    }

    public function testConnectingFindsTheAddressBooksAndNotTheCalendars(): void
    {
        $crawler = $this->client->request('GET', '/settings/contacts/carddav/connect');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="cal_dav_connect"]')->form([
            'cal_dav_connect[baseUrl]'  => self::SERVER,
            'cal_dav_connect[name]'     => 'Home',
            'cal_dav_connect[username]' => 'alice',
            'cal_dav_connect[secret]'   => 'app-password',
        ]);

        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();

        $offered = $crawler->filter('input[name="remoteIds[]"]')->each(
            static fn ($input): string => (string) $input->attr('value'),
        );

        self::assertSame([self::SERVER . '/remote.php/dav/addressbooks/users/alice/contacts/'], $offered);

        $integration = $this->connectionNamed('Home');
        self::assertSame(Provider::CardDav, $integration->provider);
        self::assertTrue($integration->isHealthy());
        self::assertSame(['PROPFIND', self::SERVER . '/.well-known/carddav'], [$this->requests[0]['method'], $this->requests[0]['url']]);
    }

    public function testTickingABookMirrorsItAndQueuesItsFirstSync(): void
    {
        $integration = $this->connection('Home');
        $remoteId    = self::SERVER . '/remote.php/dav/addressbooks/users/alice/contacts/';

        $crawler = $this->client->request('GET', '/settings/contacts/subscribe/' . $integration->id);
        $token   = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/contacts/subscribe/' . $integration->id, [
            '_token'    => $token,
            'remoteIds' => [$remoteId],
        ]);

        self::assertResponseIsSuccessful();

        $books = static::getContainer()->get(AddressBookRepository::class)->findForIntegration($integration);

        self::assertCount(1, $books);
        self::assertSame($remoteId, $books[0]->remoteId);
        self::assertSame('Contacts', $books[0]->name, 'named by the server, not the browser');
        self::assertContains((int) $books[0]->id, $this->queuedSyncs());
    }

    // ── The contacts page ─────────────────────────────────────────────────────

    public function testThePageListsSyncedAndLocalContactsAndSearchesThem(): void
    {
        $book = $this->book();
        $this->card($book, 'ada', 'Ada Lovelace', 'ada@example.com');
        $this->local('Grace Hopper', 'grace@example.com');

        $crawler = $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-contact-card]'));

        $crawler = $this->client->request('GET', '/contacts?q=hopper');

        self::assertCount(1, $crawler->filter('[data-contact-card]'));
        self::assertStringContainsString('Grace Hopper', $crawler->filter('[data-contact-card]')->text());

        $crawler = $this->client->request('GET', '/contacts?book=' . $book->id);

        self::assertCount(1, $crawler->filter('[data-contact-card]'), 'one book at a time');
        self::assertStringContainsString('Ada Lovelace', $crawler->filter('[data-contact-card]')->text());
        self::assertCount(1, $crawler->filter('[data-book-chip="local"]'));
    }

    public function testAnEditIsSavedHereMarkedPendingAndQueued(): void
    {
        $book = $this->book();
        $card = $this->card($book, 'ada', 'Ada Lovelace', 'ada@example.com', extra: "BDAY:1815-12-10\r\n");

        $crawler = $this->client->request('GET', '/contacts/' . $card->id . '/edit');

        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/contacts/' . $card->id, [
            '_token'      => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'displayName' => 'Ada King',
            'emails'      => [
                ['value' => 'ada@example.com', 'label' => 'home'],
                ['value' => 'ada@king.example', 'label' => 'work', 'primary' => '1'],
            ],
            'phones'      => [['value' => '+44 20 7946 0000', 'label' => 'mobile']],
        ]);

        self::assertResponseRedirects('/contacts/' . $card->id);

        $this->em->clear();
        $saved = $this->em->find(Contact::class, $card->id);

        self::assertSame('Ada King', $saved->displayName);
        self::assertSame(['ada@example.com', 'ada@king.example'], array_map(static fn ($e): string => $e->email, $saved->emails->toArray()));
        self::assertSame('mobile', $saved->phones->first()->label, 'the label the old form dropped');
        self::assertSame(SyncState::PendingUpdate, $saved->syncState);
        self::assertStringContainsString('BDAY:1815-12-10', (string) $saved->vcard, 'the card is patched when it is pushed, not rebuilt now');
        self::assertContains((int) $book->id, $this->queuedPushes());
        self::assertSame([], $this->requests, 'nothing is sent inline');
    }

    public function testACreatedContactLandsInTheChosenBookAsAPendingCreate(): void
    {
        $book = $this->book();

        $crawler = $this->client->request('GET', '/contacts/new');

        self::assertCount(1, $crawler->filter('select[name="addressBook"] option[value="' . $book->id . '"]'));

        $this->client->request('POST', '/contacts', [
            '_token'      => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'addressBook' => (string) $book->id,
            'displayName' => 'Grace Hopper',
            'company'     => 'US Navy',
            'emails'      => [['value' => 'grace@navy.example', 'label' => 'work']],
        ]);

        self::assertResponseRedirects();

        $card = $this->em->getRepository(Contact::class)->findOneBy(['addressBook' => $book]);

        self::assertNotNull($card);
        self::assertSame(SyncState::PendingCreate, $card->syncState);
        self::assertNull($card->href, 'not on the server until the worker sends it');
        self::assertContains((int) $book->id, $this->queuedPushes());
    }

    public function testWithoutABookChosenTheContactIsPlMailsOwn(): void
    {
        $this->book();

        $crawler = $this->client->request('GET', '/contacts/new');

        $this->client->request('POST', '/contacts', [
            '_token'      => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'addressBook' => '',
            'displayName' => 'Local Person',
        ]);

        $contact = $this->em->getRepository(Contact::class)->findOneBy(['usr' => $this->user, 'displayName' => 'Local Person']);

        self::assertNotNull($contact);
        self::assertNull($contact->addressBook);
        self::assertSame(SyncState::Clean, $contact->syncState);
        self::assertSame([], $this->queuedPushes());
    }

    public function testAContactNeedsAName(): void
    {
        $crawler = $this->client->request('GET', '/contacts/new');

        $this->client->request('POST', '/contacts', [
            '_token' => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'phones' => [['value' => '555-0100', 'label' => 'mobile']],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBinningHidesTheContactAndQueuesTheDeleteOfItsCard(): void
    {
        $book = $this->book();
        $card = $this->card($book, 'ada', 'Ada Lovelace', 'ada@example.com');

        $crawler = $this->client->request('GET', '/contacts/' . $card->id . '/edit');
        $token   = $crawler->filter('form[action="/contacts/' . $card->id . '/delete"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/contacts/' . $card->id . '/delete', ['_token' => $token]);

        self::assertResponseRedirects('/contacts');

        $this->em->clear();
        self::assertSame(SyncState::PendingDelete, $this->em->find(Contact::class, $card->id)->syncState);

        $crawler = $this->client->request('GET', '/contacts');
        self::assertCount(0, $crawler->filter('[data-contact-card]'));
    }

    public function testAReadOnlyBookCannotBeWrittenTo(): void
    {
        $book             = $this->book();
        $book->isReadOnly = true;
        $this->em->flush();

        $card = $this->card($book, 'ada', 'Ada Lovelace', 'ada@example.com');

        $crawler = $this->client->request('GET', '/contacts/' . $card->id);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/contacts/' . $card->id . '/edit"]'), 'no edit button');
        self::assertCount(1, $crawler->filter('[data-contact-book]'));

        $this->client->request('GET', '/contacts/' . $card->id . '/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/contacts/' . $card->id . '/delete', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertCount(0, $crawler->filter('select[name="addressBook"] option[value="' . $book->id . '"]'), 'not offered to save into');
    }

    public function testAContactCannotBeSavedIntoABookThatIsNotWritableOrNotTheirs(): void
    {
        $readOnly             = $this->book();
        $readOnly->isReadOnly = true;
        $this->em->flush();

        $crawler = $this->client->request('GET', '/contacts/new');

        $this->client->request('POST', '/contacts', [
            '_token'      => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'addressBook' => (string) $readOnly->id,
            'displayName' => 'Sneaky',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSomebodyElsesContactIsNotFound(): void
    {
        $stranger            = new User();
        $stranger->email     = 'contacts-stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Stranger';
        $stranger->nameLast  = 'Isolation';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);

        $theirs = new Integration($stranger, Provider::CardDav, 'Theirs');
        $theirs->baseUrl = self::SERVER;
        $this->em->persist($theirs);

        $book = new AddressBook($stranger, $theirs, self::SERVER . '/theirs/', 'Theirs');
        $this->em->persist($book);
        $this->em->flush();

        $card = $this->card($book, 'secret', 'Their Friend', 'friend@example.com');

        $this->client->request('GET', '/contacts/' . $card->id . '/edit');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/contacts?q=friend');
        self::assertCount(0, $crawler->filter('[data-contact-card]'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A Nextcloud as CardDAV meets it: `.well-known/carddav` redirects to the
     * DAV root, which names the principal, which names an address-book home
     * holding one book — and a calendar home beside it, which must be ignored.
     *
     * @param array<string, mixed> $options
     */
    private function server(string $method, string $url, array $options): MockResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url];

        $parts  = parse_url($url);
        $origin = $parts['scheme'] . '://' . $parts['host'];

        return match ($parts['path'] ?? '/') {
            '/.well-known/carddav' => new MockResponse('', [
                'http_code'        => 301,
                'response_headers' => ['location' => $origin . '/remote.php/dav/'],
            ]),
            '/remote.php/dav/' => $this->multistatus(<<<'XML'
                <d:response>
                  <d:href>/remote.php/dav/</d:href>
                  <d:propstat>
                    <d:prop>
                      <d:resourcetype><d:collection/></d:resourcetype>
                      <d:current-user-principal><d:href>/remote.php/dav/principals/users/alice/</d:href></d:current-user-principal>
                    </d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                XML),
            '/remote.php/dav/principals/users/alice/' => $this->multistatus(<<<'XML'
                <d:response>
                  <d:href>/remote.php/dav/principals/users/alice/</d:href>
                  <d:propstat>
                    <d:prop>
                      <cal:calendar-home-set><d:href>/remote.php/dav/calendars/alice/</d:href></cal:calendar-home-set>
                      <card:addressbook-home-set><d:href>/remote.php/dav/addressbooks/users/alice/</d:href></card:addressbook-home-set>
                    </d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                XML),
            '/remote.php/dav/addressbooks/users/alice/' => $this->multistatus(<<<'XML'
                <d:response>
                  <d:href>/remote.php/dav/addressbooks/users/alice/</d:href>
                  <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
                </d:response>
                <d:response>
                  <d:href>/remote.php/dav/addressbooks/users/alice/contacts/</d:href>
                  <d:propstat>
                    <d:prop>
                      <d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>
                      <d:displayname>Contacts</d:displayname>
                    </d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                <d:response>
                  <d:href>/remote.php/dav/calendars/alice/personal/</d:href>
                  <d:propstat>
                    <d:prop><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype></d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                XML),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function multistatus(string $responses): MockResponse
    {
        return new MockResponse(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . $responses . '</d:multistatus>',
            ['http_code' => 207, 'response_headers' => ['content-type' => 'application/xml; charset=utf-8']],
        );
    }

    private function connection(string $name): Integration
    {
        $integration           = new Integration($this->user, Provider::CardDav, $name);
        $integration->baseUrl  = self::SERVER;
        $integration->username = 'alice';
        $integration->secret   = 'app-password';
        $this->em->persist($integration);
        $this->em->flush();

        return $integration;
    }

    private function connectionNamed(string $name): Integration
    {
        $this->em->clear();

        $found = static::getContainer()->get(IntegrationRepository::class)->findOneBy([
            'usr'      => $this->user->id,
            'provider' => Provider::CardDav,
            'name'     => $name,
        ]);

        self::assertNotNull($found);

        return $found;
    }

    private function book(): AddressBook
    {
        $integration = $this->connection('Home ' . bin2hex(random_bytes(3)));
        $book        = new AddressBook($this->user, $integration, self::SERVER . '/remote.php/dav/addressbooks/users/alice/contacts/', 'Contacts');

        $this->em->persist($book);
        $this->em->flush();

        return $book;
    }

    /** A contact as a pull leaves it: in the book, on the server, clean. */
    private function card(AddressBook $book, string $uid, string $name, string $email, string $extra = ''): Contact
    {
        $card              = Contact::create($book->usr, $name);
        $card->addressBook = $book;
        $card->href        = $book->remoteId . $uid . '.vcf';
        $card->etag        = '"1"';
        $card->vcard       = sprintf("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:%s\r\nFN:%s\r\nEMAIL:%s\r\n%sEND:VCARD\r\n", $uid, $name, $email, $extra);

        $mapper = new VCardMapper();
        $mapper->apply($mapper->read($card->vcard) ?? self::fail('fixture card'), $card);

        $this->em->persist($card);
        static::getContainer()->get(ContactSyncTracker::class)->untracked(fn () => $this->em->flush());

        return $card;
    }

    private function local(string $name, string $email): Contact
    {
        $contact = Contact::create($this->user, $name);
        ContactEmail::create($contact, $email);

        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /** @return list<int> address-book ids with a sync queued */
    private function queuedSyncs(): array
    {
        return $this->queued('ingest', SyncAddressBookMessage::class);
    }

    /** @return list<int> address-book ids with a push queued */
    private function queuedPushes(): array
    {
        return $this->queued('export', PushAddressBookMessage::class);
    }

    /**
     * @param class-string<SyncAddressBookMessage|PushAddressBookMessage> $class
     *
     * @return list<int>
     */
    private function queued(string $transportName, string $class): array
    {
        $transport = static::getContainer()->get('messenger.transport.' . $transportName);

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $ids = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $class) {
                $ids[] = $message->addressBookId;
            }
        }

        return $ids;
    }
}
