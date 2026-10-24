<?php

declare(strict_types=1);

namespace App\Tests\Controller\AddressBook;

use App\Domain\Enum\Integration\Provider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Repository\User\UserRepository;
use App\Service\Contacts\ContactManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Add to contacts" on an address in a mail, through the real controller:
 * the dialog it opens, and its two ways out.
 *
 * The claims worth naming:
 *   - the dialog searches by the sender's name before anything is typed, and
 *     offers only contacts the person may change;
 *   - "New contact" makes one contact under the name given, once;
 *   - "Add to an existing contact" puts the address on that contact, not on a
 *     new one, and never on a contact in a read-only book or somebody else's;
 *   - "More details" opens the full editor with the name and address in it.
 */
final class ContactFromMailTest extends WebTestCase
{
    private const string USER_EMAIL = 'e2e@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::USER_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user` first');
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

    public function testTheDialogOffersTheContactsTheNameFinds(): void
    {
        $ada = $this->contact('Ada Lovelace', 'ada@home.example');
        $this->contact('Grace Hopper', 'grace@navy.example');

        $crawler = $this->dialog('ada@work.example', 'Ada Lovelace');

        self::assertSame('Ada Lovelace', $crawler->filter('#from-mail-q')->attr('value'), 'the search starts as the name');
        self::assertSame('Ada Lovelace', $crawler->filter('#from-mail-name')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-from-mail-matches] form'));
        self::assertSame('/contacts/from-mail/' . $ada->id, $crawler->filter('[data-from-mail-matches] form')->attr('action'));
    }

    public function testSearchingFindsByAnyAddressToo(): void
    {
        $grace = $this->contact('Grace Hopper', 'grace@navy.example');

        $crawler = $this->client->request('GET', '/contacts/from-mail', [
            'address' => 'amazing.grace@example.com',
            'name'    => 'Amazing',
            'q'       => 'navy',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('/contacts/from-mail/' . $grace->id, $crawler->filter('[data-from-mail-matches] form')->attr('action'));
    }

    public function testAContactInAReadOnlyBookIsNotOffered(): void
    {
        $card              = $this->contact('Ada Lovelace', 'ada@home.example');
        $this->intoReadOnlyBook($card);

        $crawler = $this->dialog('ada@work.example', 'Ada Lovelace');

        self::assertCount(0, $crawler->filter('[data-from-mail-matches]'));
        self::assertStringContainsString('No contact matches.', $crawler->filter('[data-contacts-from-mail]')->text());
    }

    public function testANewContactIsMadeUnderTheNameGivenAndOnlyOnce(): void
    {
        $token = $this->token();

        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/contacts/from-mail', [
                '_token'  => $token,
                'address' => 'New.Contact@Example.com',
                'name'    => 'Renamed In The Dialog',
            ]);

            self::assertResponseIsSuccessful();
        }

        $rows = $this->emails('new.contact@example.com');

        self::assertCount(1, $rows, 'pressed twice, one contact');
        self::assertSame('Renamed In The Dialog', $rows[0]->contact->displayName);
        self::assertTrue($rows[0]->isPrimary);
        self::assertStringContainsString('data-contact-badge', (string) $this->client->getResponse()->getContent());
    }

    public function testAnExistingContactGetsTheAddressBesideItsOwn(): void
    {
        $ada = $this->contact('Ada Lovelace', 'ada@home.example');

        $this->client->request('POST', '/contacts/from-mail/' . $ada->id, [
            '_token'  => $this->token(),
            'address' => 'ada@work.example',
            'name'    => 'Ada L.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/contacts/' . $ada->id, (string) $this->client->getResponse()->getContent(), 'the badge points at Ada');

        $this->em->clear();
        $ada = $this->em->find(Contact::class, $ada->id);

        $emails = array_map(static fn (ContactEmail $e): string => $e->email, $ada->emails->toArray());
        sort($emails);

        self::assertSame(['ada@home.example', 'ada@work.example'], $emails);
        self::assertSame('Ada Lovelace', $ada->displayName, 'the name the mail gave is not taken');

        foreach ($ada->emails as $row) {
            self::assertSame('ada@home.example' === $row->email, $row->isPrimary, 'her own address stays the primary one');
        }
        self::assertSame(0, $this->em->getRepository(Contact::class)->count(['usr' => $this->user, 'displayName' => 'Ada L.']), 'no second contact');
    }

    public function testAnAddressAlreadyHeldStaysWithItsContact(): void
    {
        $grace = $this->contact('Grace Hopper', 'grace@navy.example');
        $ada   = $this->contact('Ada Lovelace', 'ada@home.example');

        $this->client->request('POST', '/contacts/from-mail/' . $ada->id, [
            '_token'  => $this->token(),
            'address' => 'grace@navy.example',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/contacts/' . $grace->id, (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->emails('grace@navy.example'));
    }

    public function testAReadOnlyOrSomebodyElsesContactCannotBeAddedTo(): void
    {
        $card              = $this->contact('Ada Lovelace', 'ada@home.example');
        $this->intoReadOnlyBook($card);

        $token = $this->token();

        $this->client->request('POST', '/contacts/from-mail/' . $card->id, ['_token' => $token, 'address' => 'ada@work.example']);
        self::assertResponseStatusCodeSame(403);

        $stranger = new User();
        $stranger->email     = 'from-mail-stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Stranger';
        $stranger->nameLast  = 'Isolation';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);
        $theirs = Contact::create($stranger, 'Their Friend');
        $this->em->persist($theirs);
        $this->em->flush();

        $this->client->request('POST', '/contacts/from-mail/' . $theirs->id, ['_token' => $token, 'address' => 'ada@work.example']);
        self::assertResponseStatusCodeSame(404);

        self::assertCount(0, $this->emails('ada@work.example'));
    }

    public function testMoreDetailsOpensTheEditorWithTheNameAndAddress(): void
    {
        $crawler = $this->dialog('new.person@example.com', 'New Person');

        $href = $crawler->filter('a[href^="/contacts/new/full"]')->attr('href');

        $crawler = $this->client->request('GET', $href);

        self::assertResponseIsSuccessful();
        self::assertSame('New Person', $crawler->filter('input[name="displayName"]')->attr('value'));
        self::assertCount(1, $crawler->filter('input[name^="emails"][value="new.person@example.com"]'));
    }

    public function testTheBadgeOpensTheDialog(): void
    {
        $crawler = $this->client->request('GET', '/contacts/from-mail', ['address' => 'nobody@example.com']);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-from-mail-matches]'), 'no name, no search to start with');

        $this->client->request('GET', '/contacts/from-mail', ['address' => 'not an address']);
        self::assertResponseStatusCodeSame(404);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function dialog(string $address, string $name): Crawler
    {
        $crawler = $this->client->request('GET', '/contacts/from-mail', ['address' => $address, 'name' => $name]);

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The dialog's CSRF token, read off the dialog as the browser would. One
     * token for every address, so it is read from a dialog for an address
     * nobody holds — one that is held offers no forms.
     */
    private function token(): string
    {
        return (string) $this->dialog('token.reader@example.com', '')->filter('input[name="_token"]')->first()->attr('value');
    }

    private function contact(string $name, string $email): Contact
    {
        $manager = static::getContainer()->get(ContactManager::class);
        $contact = $manager->create($this->user, ['displayName' => $name]);
        $manager->addEmail($contact, $email, 'home', true);

        return $contact;
    }

    /**
     * The contact moved into a read-only book, as a pull would leave it there.
     * Untracked: ContactSyncTracker refuses any change to a card in a
     * read-only book, this one included.
     */
    private function intoReadOnlyBook(Contact $contact): void
    {
        $contact->addressBook = $this->readOnlyBook();
        static::getContainer()->get(ContactSyncTracker::class)->untracked(fn () => $this->em->flush());
    }

    private function readOnlyBook(): AddressBook
    {
        $integration          = new Integration($this->user, Provider::CardDav, 'Shared ' . bin2hex(random_bytes(3)));
        $integration->baseUrl = 'https://cloud.carddav.test';
        $this->em->persist($integration);

        $book             = new AddressBook($this->user, $integration, 'https://cloud.carddav.test/shared/', 'Shared');
        $book->isReadOnly = true;
        $this->em->persist($book);
        $this->em->flush();

        return $book;
    }

    /** @return list<ContactEmail> */
    private function emails(string $address): array
    {
        $this->em->clear();

        /** @var list<ContactEmail> */
        return $this->em->createQueryBuilder()
            ->select('e', 'c')
            ->from(ContactEmail::class, 'e')
            ->innerJoin('e.contact', 'c')
            ->where('c.usr = :user')
            ->andWhere('e.email = :email')
            ->setParameter('user', $this->user->id)
            ->setParameter('email', $address)
            ->getQuery()
            ->getResult();
    }
}
