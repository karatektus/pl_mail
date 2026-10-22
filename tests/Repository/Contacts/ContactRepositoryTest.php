<?php

declare(strict_types=1);

namespace App\Tests\Repository\Contacts;

use App\Entity\Contacts\ContactEmail;
use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Repository\Contacts\ContactRepository;
use App\Tests\Support\Contacts\ContactsKernelFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The address book's queries, against Postgres.
 *
 * Two failures the old queries had are pinned here: a contact with several
 * addresses listed once per address (so a page could cut it short and repeat
 * it on the next), and a contact found by one of its addresses shown with
 * only that address — a fetch-joined, filtered collection Doctrine then takes
 * for the whole one.
 */
final class ContactRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ContactRepository $repository;
    private ContactsKernelFixture $fixture;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(ContactRepository::class);
        $this->fixture    = new ContactsKernelFixture($this->em, $container->get(ContactSyncTracker::class));

        $this->connection->beginTransaction();
        $this->user = $this->fixture->user();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAContactWithSeveralAddressesIsListedOnce(): void
    {
        $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com', 'ada@work.example', 'ada@home.example']);
        $this->fixture->contact($this->user, 'Grace Hopper', ['grace@example.com']);

        self::assertSame(['Ada Lovelace', 'Grace Hopper'], $this->names($this->repository->findForUser($this->user, null, null, 'name', 2, 0)));
        self::assertSame(2, $this->repository->countForUser($this->user, null));
    }

    public function testFoundByOneAddressItStillHasAllOfThem(): void
    {
        $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com', 'countess@work.example']);
        $this->em->clear();

        $found = $this->repository->findForUser($this->user, 'countess', null, 'name', 10, 0);

        self::assertCount(1, $found);
        self::assertCount(2, $found[0]->emails, 'not just the one that matched');
    }

    public function testTheSearchTakesPercentAndUnderscoreLiterally(): void
    {
        $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com']);

        self::assertSame([], $this->repository->findForUser($this->user, '%', null, 'name', 10, 0));
        self::assertSame([], $this->repository->findForUser($this->user, '_', null, 'name', 10, 0));
    }

    public function testTheListCanBeNarrowedToOneBook(): void
    {
        $book = $this->fixture->book($this->user, 'Nextcloud');
        $this->fixture->contact($this->user, 'Local Person');
        $this->fixture->contact($this->user, 'Synced Person', book: $book);

        self::assertSame(['Local Person'], $this->names($this->repository->findForUser($this->user, null, null, 'name', 10, 0, 'local')));
        self::assertSame(['Synced Person'], $this->names($this->repository->findForUser($this->user, null, null, 'name', 10, 0, $book->id)));
        self::assertSame(2, $this->repository->countForUser($this->user, null));
        self::assertSame(1, $this->repository->countForUser($this->user, null, $book->id));
    }

    public function testANameMatchOffersEveryAddressAndAnAddressMatchOnlyThatOne(): void
    {
        $this->fixture->contact($this->user, 'Grace Hopper', ['grace@navy.example', 'gh@home.example']);

        self::assertSame(['grace@navy.example', 'gh@home.example'], $this->emails($this->repository->findForAutocomplete($this->user, 'grace', 8)));
        self::assertSame(['gh@home.example'], $this->emails($this->repository->findForAutocomplete($this->user, 'home.example', 8)));
    }

    public function testAutocompleteLeavesOutTheBinAndOtherPeople(): void
    {
        $binned            = $this->fixture->contact($this->user, 'Binned Ada', ['binned@example.com']);
        $binned->trashedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->fixture->contact($this->fixture->user(), 'Someone Else\'s Ada', ['other@example.com']);

        self::assertSame([], $this->repository->findForAutocomplete($this->user, 'ada', 8));
    }

    public function testASyncedBookIsIndexedByHrefAndUid(): void
    {
        $book    = $this->fixture->book($this->user);
        $contact = $this->fixture->contact($this->user, 'Ada', book: $book);
        $this->fixture->contact($this->user, 'Not In The Book');

        $index = $this->repository->indexForBook($book);

        self::assertSame([$contact->href => $contact], $index['byHref']);
        self::assertSame([$contact->uid => $contact], $index['byUid']);
        self::assertSame([$book->id => 1], $this->repository->countByBook([$book]));
    }

    /**
     * @param list<\App\Entity\Contacts\Contact> $contacts
     *
     * @return list<string>
     */
    private function names(array $contacts): array
    {
        return array_map(static fn ($contact): string => $contact->displayName, $contacts);
    }

    /**
     * @param list<ContactEmail> $rows
     *
     * @return list<string>
     */
    private function emails(array $rows): array
    {
        return array_map(static fn (ContactEmail $row): string => $row->email, $rows);
    }
}
