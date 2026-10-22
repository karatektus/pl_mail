<?php

declare(strict_types=1);

namespace App\Tests\Controller\AddressBook;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Integration\Provider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
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

    /**
     * Two connections to the same server reach the same book under the same
     * remote id — a separate CardDAV connection beside the Nextcloud one is the
     * real case. Mirrored through one, it is offered locked through the other,
     * and a crafted tick is refused, or every contact in it would show twice
     * (#317).
     */
    public function testABookAlreadyMirroredThroughAnotherConnectionCannotBeTickedAgain(): void
    {
        $home     = $this->connection('Home');
        $work     = $this->connection('Work');
        $remoteId = self::SERVER . '/remote.php/dav/addressbooks/users/alice/contacts/';

        $this->em->persist(new AddressBook($this->user, $home, $remoteId, 'Contacts'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings/contacts/subscribe/' . $work->id);

        self::assertResponseIsSuccessful();

        $box = $crawler->filter('input[name="remoteIds[]"]');
        self::assertSame($remoteId, $box->attr('value'));
        self::assertNotNull($box->attr('disabled'), 'locked, not merely unticked');
        self::assertSame('Already mirrored through Home', trim($crawler->filter('[data-book-elsewhere]')->text()));

        $this->client->request('POST', '/settings/contacts/subscribe/' . $work->id, [
            '_token'    => $crawler->filter('input[name="_token"]')->attr('value'),
            'remoteIds' => [$remoteId],
        ]);

        self::assertResponseIsSuccessful();

        $books = static::getContainer()->get(AddressBookRepository::class);
        self::assertCount(0, $books->findForIntegration($work), 'the post is refused on the server too');
        self::assertCount(1, $books->findForIntegration($home), 'and the first mirror is left as it was');

        // The connection that mirrors it still lists it as its own, ticked and editable.
        $own = $this->client->request('GET', '/settings/contacts/subscribe/' . $home->id)->filter('input[name="remoteIds[]"]');
        self::assertNotNull($own->attr('checked'));
        self::assertNull($own->attr('disabled'));
    }

    // ── Google and Microsoft accounts ─────────────────────────────────────────

    /**
     * An account connected before contacts were asked for is listed with the
     * one thing to do, a reconnect, and no address books.
     */
    public function testAnAccountThatPredatesContactsIsOfferedTheReconnect(): void
    {
        $account                     = $this->oauthAccount('before@joder.dev');
        $account->oauthGrantedScopes = 'https://mail.google.com/ https://www.googleapis.com/auth/calendar';
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=contacts');
        $row     = $crawler->filter('[data-contact-account="' . $account->id . '"]');

        self::assertCount(1, $row);
        self::assertCount(1, $row->filter('a[href="/settings/health/reconnect/' . $account->id . '"]'));
        self::assertCount(0, $row->filter('[data-address-book]'));
    }

    /**
     * A Microsoft account's reconnect warns first that an organisation which
     * approves apps for its users refuses it until the administrator approves
     * contacts. A Google account's does not: Google has no such case.
     */
    public function testAMicrosoftAccountsReconnectWarnsAboutAdminApproval(): void
    {
        $microsoft                     = $this->oauthAccount('outlook@joder.dev', MailProvider::Microsoft);
        $microsoft->oauthGrantedScopes = 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access';
        $google                        = $this->oauthAccount('gmail@joder.dev');
        $google->oauthGrantedScopes    = 'https://mail.google.com/';
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=contacts');

        self::assertCount(1, $crawler->filter('[data-contact-account="' . $microsoft->id . '"] [data-admin-consent-hint]'));
        self::assertCount(0, $crawler->filter('[data-contact-account="' . $google->id . '"] [data-admin-consent-hint]'));
    }

    /** One whose grant reaches contacts shows its books and the chooser. */
    public function testAnAccountWithContactsShowsItsBooks(): void
    {
        $account = $this->oauthAccount('with@joder.dev');
        $book    = new AddressBook($this->user, $account, 'people/me', 'Google Contacts');
        $this->em->persist($book);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=contacts');
        $row     = $crawler->filter('[data-contact-account="' . $account->id . '"]');

        self::assertCount(1, $row->filter('[data-address-book="' . $book->id . '"]'));
        self::assertSame(
            '/settings/contacts/subscribe/account/' . $account->id,
            $row->filter('[data-ui--modal-src-value]')->attr('data-ui--modal-src-value'),
        );
    }

    /** A contact in an account's book says where it is synced, by the account's address. */
    public function testAContactInAnAccountsBookShowsWhereItIsSynced(): void
    {
        $account = $this->oauthAccount('synced@joder.dev');
        $book    = new AddressBook($this->user, $account, 'people/me', 'Google Contacts');
        $this->em->persist($book);

        $contact              = Contact::create($this->user, 'Ada Lovelace');
        $contact->addressBook = $book;
        $contact->href        = 'people/c1';
        $this->em->persist($contact);
        $this->em->flush();

        $this->client->request('GET', '/contacts/' . $contact->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('synced@joder.dev', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/contacts/' . $contact->id . '/edit');

        self::assertResponseIsSuccessful();
    }

    /** The chooser for an account is refused for somebody else's. */
    public function testNobodyChoosesTheBooksOfAnotherUsersAccount(): void
    {
        $other           = $this->oauthAccount('theirs@joder.dev');
        $stranger            = new User();
        $stranger->email     = 'stranger-' . uniqid() . '@example.test';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $stranger->nameFirst = 'S';
        $stranger->nameLast  = 'T';
        $this->em->persist($stranger);
        $other->usr = $stranger;
        $this->em->flush();

        $this->client->request('GET', '/settings/contacts/subscribe/account/' . $other->id);

        self::assertResponseStatusCodeSame(403);
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
            'birthday'    => ['day' => '10', 'month' => '12', 'year' => '1815'],
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

    public function testTheEditorShowsAndSavesEveryField(): void
    {
        $book = $this->book();
        $card = $this->card($book, 'ada', 'Ada Lovelace', 'ada@example.com', extra: "BDAY:1815-12-10\r\nitem1.X-ABRELATEDNAMES:Mary\r\nitem1.X-ABLabel:Godmother\r\n");

        $crawler = $this->client->request('GET', '/contacts/' . $card->id . '/edit');

        self::assertSame('10', $crawler->filter('input[name="birthday[day]"]')->attr('value'));
        self::assertSame('1815', $crawler->filter('input[name="birthday[year]"]')->attr('value'));
        self::assertSame('Mary', $crawler->filter('input[name="relations[0][name]"]')->attr('value'));
        self::assertSame('Godmother', $crawler->filter('select[name="relations[0][label]"] option[selected]')->attr('value'), 'a custom label is offered, so it survives the save');

        $this->client->request('POST', '/contacts/' . $card->id, [
            '_token'         => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'displayName'    => 'Ada Lovelace',
            'middleName'     => 'King',
            'department'     => 'Mathematics',
            'birthday'       => ['day' => '10', 'month' => '12', 'year' => ''],
            'dates'          => [['label' => 'anniversary', 'day' => '8', 'month' => '7', 'year' => '1835'], ['label' => 'other', 'day' => '', 'month' => '', 'year' => '']],
            'relations'      => [['label' => 'Godmother', 'name' => 'Mary'], ['label' => 'spouse', 'name' => 'William']],
            'urls'           => [['label' => 'blog', 'url' => 'https://ada.example']],
            'ims'            => [['service' => 'xmpp', 'handle' => 'ada@jabber.example', 'label' => 'home']],
            'customFields'   => [['label' => 'Engine', 'value' => 'Analytical']],
        ]);

        self::assertResponseRedirects('/contacts/' . $card->id);

        $this->em->clear();
        $saved = $this->em->find(Contact::class, $card->id);

        self::assertSame('King', $saved->middleName);
        self::assertSame('Mathematics', $saved->department);
        self::assertSame('--12-10', $saved->birthday, 'no year given, none kept');
        self::assertSame([['label' => 'anniversary', 'date' => '1835-07-08']], $saved->dates, 'an empty date row is dropped');
        self::assertSame([['label' => 'Godmother', 'name' => 'Mary'], ['label' => 'spouse', 'name' => 'William']], $saved->relations);
        self::assertSame([['service' => 'xmpp', 'handle' => 'ada@jabber.example', 'label' => 'home']], $saved->ims);
        self::assertSame(SyncState::PendingUpdate, $saved->syncState);

        $page = $this->client->request('GET', '/contacts/' . $card->id);

        self::assertStringContainsString('William', $page->text());
        self::assertStringContainsString('ada@jabber.example', $page->text());
        self::assertStringContainsString('Analytical', $page->text());
    }

    public function testAPhotoCanBeRemovedAndTheRemovalGoesToTheServer(): void
    {
        $book            = $this->book();
        $card            = $this->card($book, 'ada', 'Ada Lovelace', 'ada@example.com');
        $card->photoPath = 'contacts/0/pulled.jpg';
        static::getContainer()->get(ContactSyncTracker::class)->untracked(fn () => $this->em->flush());

        $crawler = $this->client->request('GET', '/contacts/' . $card->id . '/edit');
        $form    = $crawler->filter('form[action$="/photo/remove"]');

        self::assertCount(1, $form);

        $this->client->request('POST', '/contacts/' . $card->id . '/photo/remove', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/contacts/' . $card->id . '/edit');

        $this->em->clear();
        $saved = $this->em->find(Contact::class, $card->id);

        self::assertNull($saved->photoPath);
        self::assertTrue($saved->photoDirty, 'the push removes it at the server too');
        self::assertSame(SyncState::PendingUpdate, $saved->syncState);
    }

    public function testACreatedContactLandsInTheChosenBookAsAPendingCreate(): void
    {
        $book = $this->book();

        $crawler = $this->client->request('GET', '/contacts/new');

        self::assertCount(1, $crawler->filter('input[type="radio"][name="addressBook"][value="' . $book->id . '"]'), 'one book: a button, not a dropdown');

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
        self::assertCount(0, $crawler->filter('[name="addressBook"][value="' . $book->id . '"], [name="addressBook"] option[value="' . $book->id . '"]'), 'not offered to save into');
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

    private function oauthAccount(string $email, MailProvider $provider = MailProvider::Google): Account
    {
        $account = new Account();

        $account->usr               = $this->user;
        $account->name              = $email;
        $account->username          = $email;
        $account->email             = $email;
        $account->authType          = AuthType::OAuth2->value;
        $account->oauthProvider     = $provider->value;
        $account->oauthAccessToken  = 'access-token';
        $account->oauthRefreshToken = 'refresh-token';
        $account->isActive          = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
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
