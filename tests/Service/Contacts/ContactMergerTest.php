<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\ContactReadOnlyException;
use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Infrastructure\Messaging\Message\DeleteRemoteContactMessage;
use App\Service\Contacts\ContactMerger;
use App\Tests\Support\Contacts\ContactsKernelFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Merging duplicates when some of them live on a CardDAV server.
 *
 * The kept contact's card is updated with what it gained, a merged contact's
 * card is deleted from its server, and nothing from a read-only book takes
 * part — neither its card nor its deletion would be accepted.
 */
final class ContactMergerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ContactMerger $merger;
    private ContactsKernelFixture $fixture;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->merger     = $container->get(ContactMerger::class);
        $this->fixture    = new ContactsKernelFixture($this->em, $container->get(ContactSyncTracker::class));

        $this->connection->beginTransaction();
        $this->user = $this->fixture->user();
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testMergingASyncedDuplicateUpdatesTheKeptCardAndDeletesTheOther(): void
    {
        $book   = $this->fixture->book($this->user);
        $keep   = $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com'], $book);
        $merged = $this->fixture->contact($this->user, 'Ada L.', ['ada@work.example'], $book);
        $href   = $merged->href;

        $this->merger->merge((int) $keep->id, [(int) $merged->id], $this->user);

        self::assertSame(SyncState::PendingUpdate, $keep->syncState, 'it gained an address');
        self::assertCount(2, $keep->emails);

        $deletes = array_values(array_filter($this->sent(), static fn (object $m): bool => $m instanceof DeleteRemoteContactMessage));
        self::assertCount(1, $deletes);
        self::assertSame($href, $deletes[0]->href);
    }

    public function testAContactFromAReadOnlyBookCannotBeMerged(): void
    {
        $readOnly = $this->fixture->book($this->user, 'Directory', readOnly: true);
        $keep     = $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com']);
        $merged   = $this->fixture->contact($this->user, 'Ada (directory)', ['ada@example.com'], $readOnly);

        $this->expectException(ContactReadOnlyException::class);

        $this->merger->merge((int) $keep->id, [(int) $merged->id], $this->user);
    }

    /** @return list<object> */
    private function sent(): array
    {
        return array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent());
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
