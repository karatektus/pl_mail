<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Event\Listener;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\ContactReadOnlyException;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Infrastructure\Messaging\Message\DeleteRemoteContactMessage;
use App\Infrastructure\Messaging\Message\PushAddressBookMessage;
use App\Tests\Support\Contacts\ContactsKernelFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Every write to a synced contact reaches its server, whoever made it.
 *
 * Through the real EntityManager and flush, because what is under test is
 * that the listener sees what Doctrine sees: a field assigned anywhere, a
 * child row added or removed, a row deleted outright. A writer cannot forget
 * to say "push this", because nothing asks it to.
 */
final class ContactSyncTrackerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ContactSyncTracker $tracker;
    private ContactsKernelFixture $fixture;
    private User $user;
    private AddressBook $book;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->tracker    = $container->get(ContactSyncTracker::class);
        $this->fixture    = new ContactsKernelFixture($this->em, $this->tracker);

        $this->connection->beginTransaction();
        $this->user = $this->fixture->user();
        $this->book = $this->fixture->book($this->user);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnEditedFieldMakesTheContactPendingAndStartsAPush(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com'], $this->book);

        $contact->company = 'Analytical Engines';
        $this->em->flush();

        self::assertSame(SyncState::PendingUpdate, $this->reloaded($contact)->syncState, 'written to the database, not just the object');
        self::assertSame([$this->book->id], $this->pushedBooks());

        $ingest = self::getContainer()->get('messenger.transport.ingest');
        self::assertInstanceOf(InMemoryTransport::class, $ingest);
        self::assertSame([], $ingest->getSent(), 'an edit does not queue behind the mail syncs');
    }

    public function testAChildRowAloneCountsAsAnEdit(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com'], $this->book);

        ContactPhone::create($contact, '+4930123', 'mobile');
        $this->em->flush();
        self::assertSame(SyncState::PendingUpdate, $this->reloaded($contact)->syncState, 'a phone added');

        $this->settle($contact);
        $contact->emails->removeElement($contact->emails->first());
        $this->em->flush();
        self::assertSame(SyncState::PendingUpdate, $this->reloaded($contact)->syncState, 'an address removed');
    }

    public function testANewContactInASyncedBookIsACreate(): void
    {
        $contact              = Contact::create($this->user, 'Grace Hopper');
        $contact->addressBook = $this->book;
        ContactEmail::create($contact, 'grace@navy.example');
        $this->em->persist($contact);
        $this->em->flush();

        self::assertSame(SyncState::PendingCreate, $this->reloaded($contact)->syncState);
        self::assertSame([$this->book->id], $this->pushedBooks());
    }

    public function testAnEditBeforeTheFirstPushStaysACreate(): void
    {
        $contact = $this->fixture->contact($this->user, 'Not Yet Sent', book: $this->book, onServer: false);
        $contact->syncState = SyncState::PendingCreate;
        $this->settle($contact);

        $contact->notes = 'still unsent';
        $this->em->flush();

        self::assertSame(SyncState::PendingCreate, $this->reloaded($contact)->syncState);
    }

    public function testBookkeepingIsNotAnEdit(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', book: $this->book);

        $contact->etag         = '"2"';
        $contact->photoVersion = 'https://lh3.googleusercontent.com/a';
        $this->em->flush();

        self::assertSame(SyncState::Clean, $this->reloaded($contact)->syncState);
        self::assertSame([], $this->pushedBooks());
    }

    public function testANewPhotoIsAnEditAndMarksThePhotoForSending(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', book: $this->book);

        $contact->photoPath = 'contacts/1/ada.jpg';
        $this->em->flush();

        self::assertSame(SyncState::PendingUpdate, $this->reloaded($contact)->syncState);
        self::assertTrue($contact->photoDirty);
        self::assertTrue((bool) $this->connection->fetchOne('SELECT photo_dirty FROM contacts_contact WHERE id = ?', [$contact->id]), 'written, not just set');
        self::assertSame([$this->book->id], $this->pushedBooks());
    }

    public function testALocalContactsPhotoIsNotMarked(): void
    {
        $contact = $this->fixture->contact($this->user, 'Local Person');

        $contact->photoPath = 'contacts/1/local.jpg';
        $this->em->flush();

        self::assertFalse($contact->photoDirty, 'there is nowhere to send it');
    }

    public function testALabelledListChangedIsAnEdit(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', book: $this->book);

        $contact->relations = [['label' => 'spouse', 'name' => 'William']];
        $this->em->flush();

        self::assertSame(SyncState::PendingUpdate, $this->reloaded($contact)->syncState);
    }

    public function testBinningASyncedContactQueuesTheDeleteOfItsCard(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', book: $this->book);

        $contact->trashedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame(SyncState::PendingDelete, $this->reloaded($contact)->syncState);
        self::assertSame([$this->book->id], $this->pushedBooks());

        // Restored before the push ran: there is nothing to delete after all.
        $contact->trashedAt = null;
        $this->em->flush();

        self::assertSame(SyncState::PendingUpdate, $this->reloaded($contact)->syncState);
    }

    public function testBinningAContactTheServerNeverSawLetsGoAtOnce(): void
    {
        $contact = $this->fixture->contact($this->user, 'Not Yet Sent', book: $this->book, onServer: false);

        $contact->trashedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertNull($this->reloaded($contact)->addressBook);
        self::assertSame(SyncState::Clean, $contact->syncState);
    }

    /** A merge or a purge removes the row; its card goes once the flush is done. */
    public function testDeletingTheRowDeletesTheCard(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', book: $this->book);
        $href    = $contact->href;

        $this->em->remove($contact);
        $this->em->flush();

        $deletes = array_values(array_filter($this->sent(), static fn (object $m): bool => $m instanceof DeleteRemoteContactMessage));

        self::assertCount(1, $deletes);
        self::assertSame($this->book->id, $deletes[0]->addressBookId);
        self::assertSame($href, $deletes[0]->href);
        self::assertSame('"1"', $deletes[0]->etag);
    }

    public function testAContactInAReadOnlyBookCannotBeChanged(): void
    {
        $book    = $this->fixture->book($this->user, 'Company directory', readOnly: true);
        $contact = $this->fixture->contact($this->user, 'The CEO', book: $book);

        $contact->displayName = 'Someone Else';

        $this->expectException(ContactReadOnlyException::class);
        $this->em->flush();
    }

    public function testLocalContactsAreNotTracked(): void
    {
        $contact = $this->fixture->contact($this->user, 'Local Person', ['local@example.com']);

        $contact->company = 'Acme';
        $this->em->flush();

        self::assertSame(SyncState::Clean, $this->reloaded($contact)->syncState);
        self::assertSame([], $this->sent());
    }

    public function testWhatTheSyncWritesIsNotAnEdit(): void
    {
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', book: $this->book);

        $this->tracker->untracked(function () use ($contact): void {
            $contact->displayName = 'Ada King';
            $this->em->flush();
        });

        self::assertSame(SyncState::Clean, $this->reloaded($contact)->syncState);
        self::assertSame([], $this->sent());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function reloaded(Contact $contact): Contact
    {
        $state = $this->connection->fetchAssociative('SELECT sync_state, address_book_id FROM contacts_contact WHERE id = ?', [$contact->id]);

        self::assertIsArray($state);
        self::assertSame($contact->syncState->value, $state['sync_state'], 'the object and the row agree');
        self::assertSame($contact->addressBook?->id, null === $state['address_book_id'] ? null : (int) $state['address_book_id']);

        return $contact;
    }

    /** Back to clean, as a push would leave it. */
    private function settle(Contact $contact): void
    {
        $this->tracker->untracked(function () use ($contact): void {
            $contact->syncState = SyncState::Clean;
            $this->em->flush();
        });
        $this->transport()->reset();
    }

    /** @return list<int> */
    private function pushedBooks(): array
    {
        return array_values(array_unique(array_map(
            static fn (PushAddressBookMessage $m): int => $m->addressBookId,
            array_values(array_filter($this->sent(), static fn (object $m): bool => $m instanceof PushAddressBookMessage)),
        )));
    }

    /** @return list<object> */
    private function sent(): array
    {
        return array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent());
    }

    /**
     * export: a push and a remote delete are plMail writing outward, and go
     * with everything else that does. See messenger.yaml.
     */
    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
