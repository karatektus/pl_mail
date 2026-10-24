<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Repository\Mail\ContactRepository;
use App\Tests\Support\Contacts\ContactsKernelFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the composer's To, Cc and Bcc offer while somebody types.
 *
 * The claims: a contact in an address book is offered although nobody has
 * mailed it — the field used to search the harvest alone and answered "No
 * results found"; an address the harvest knows is offered under its id, as
 * before, so a recipient already on a draft still counts as chosen; and
 * somebody else's contacts are never offered. The ranking itself is
 * ContactSuggesterTest's.
 */
final class RecipientSuggestionsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private ContactsKernelFixture $fixture;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->fixture    = new ContactsKernelFixture($this->em, $container->get(ContactSyncTracker::class));

        $this->connection->beginTransaction();
        $this->user = $this->fixture->user();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAddressBookContactNobodyMailedIsOfferedByItsAddress(): void
    {
        $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com'], $this->fixture->book($this->user));
        $this->fixture->contact($this->user, 'Linus Torvalds', ['linus@example.com']);

        self::assertSame([
            ['value' => 'ada@example.com', 'text' => 'Ada Lovelace <ada@example.com>'],
        ], $this->results('Ada'));
        self::assertSame([
            ['value' => 'linus@example.com', 'text' => 'Linus Torvalds <linus@example.com>'],
        ], $this->results('linus'), "plMail's own contacts too");
    }

    public function testAnAddressTheHarvestKnowsIsOfferedByItsId(): void
    {
        $harvest = static::getContainer()->get(ContactRepository::class);
        $harvest->createUnsent($this->user, ['grace@navy.example']);
        $known = $harvest->findByEmailsForUser($this->user, ['grace@navy.example'])['grace@navy.example'];

        $this->fixture->contact($this->user, 'Grace Hopper', ['grace@navy.example']);

        self::assertSame([
            ['value' => (string) $known->id, 'text' => 'Grace Hopper <grace@navy.example>'],
        ], $this->results('grace'), 'one suggestion, under the id the draft already holds, named by the address book');
    }

    public function testSomebodyElsesContactsAreNotOffered(): void
    {
        $other = $this->fixture->user();
        $this->fixture->contact($other, 'Ada Lovelace', ['ada@example.com']);
        static::getContainer()->get(ContactRepository::class)->createUnsent($other, ['ada@elsewhere.example']);

        self::assertSame([], $this->results('ada'));
    }

    public function testABlankQueryOffersNothing(): void
    {
        $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com']);

        self::assertSame([], $this->results(' '));
    }

    /** @return list<array{value: string, text: string}> */
    private function results(string $query): array
    {
        $this->client->request('GET', '/contacts/autocomplete/recipients', ['query' => $query]);

        self::assertResponseIsSuccessful();

        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($json);

        return $json['results'];
    }
}
