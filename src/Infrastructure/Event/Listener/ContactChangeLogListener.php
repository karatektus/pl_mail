<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Domain\Enum\Contacts\ContactChangeKind;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Writes the contact change log, from inside the flush that causes the change.
 *
 * CalendarChangeLogListener's design, for its reasons, which apply here twice
 * over: Contact and its emails, phones and addresses are public mutable
 * properties written from the contact page, both JMAP surfaces, the merger,
 * the importer, the harvest and every address-book sync. ContactSyncTracker
 * asks Doctrine what changed for the same reason. A recorder each writer had
 * to call would be a log with holes in it, which is worse than none.
 *
 * What a client sees:
 *
 * - **a contact created**, or restored from the bin: created;
 * - **edited** — a field, or an email, phone or address added, changed or
 *   removed, or moved to another address book: updated;
 * - **binned**, or deleted outright: destroyed. A contact in the bin is gone
 *   to a JMAP client, and comes back as new if it is restored.
 *
 * Bookkeeping is ignored as ContactSyncTracker ignores it — the sync state, the
 * remote server's href, etag and card, the photo's flags — so a pull that
 * changes nothing a client can see does not wake every client. Address books
 * are logged as rows without a contact: created, renamed, removed.
 *
 * onFlush collects and postFlush writes plain SQL, as the calendar listener
 * does and for the same two reasons: an insert has no id until the flush, and
 * persisting from postFlush re-runs somebody else's unit of work.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ContactChangeLogListener
{
    /** Fields no client can observe moving. */
    private const array IGNORED = [
        'createdAt',
        'updatedAt',
        'href',
        'etag',
        'vcard',
        'remoteResource',
        'syncState',
        'photoDirty',
        'photoVersion',
        'source',
        'trashedAt',
    ];

    /** An address book's sync plumbing; its name and read-only flag are what a client draws. */
    private const array IGNORED_BOOK = ['createdAt', 'updatedAt', 'syncToken', 'lastSyncedAt', 'lastSyncError'];

    /** @var list<array{user:int, contact:?int, entity:?Contact, book:?int, bookEntity:?AddressBook, kind:ContactChangeKind}> */
    private array $pending = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        // What a flush that threw left queued describes changes the database
        // rolled back; see CalendarChangeLogListener.
        $this->pending = [];

        $uow = $args->getObjectManager()->getUnitOfWork();

        /** @var array<int, true> $logged contacts this flush already has a row for */
        $logged = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Contact) {
                $logged[spl_object_id($entity)] = true;

                if (null === $entity->trashedAt) {
                    $this->queue($entity, ContactChangeKind::Created, deferId: true);
                }
            } elseif ($entity instanceof AddressBook) {
                $this->queueBook($entity, ContactChangeKind::Created);
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Contact) {
                $logged[spl_object_id($entity)] = true;

                // One in the bin was destroyed, to a client, when it went there.
                if (null === $entity->trashedAt) {
                    $this->queue($entity, ContactChangeKind::Destroyed);
                }
            } elseif ($entity instanceof AddressBook) {
                $this->queueBook($entity, ContactChangeKind::Destroyed);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Contact) {
                $changes = $uow->getEntityChangeSet($entity);

                if (true === array_key_exists('trashedAt', $changes)) {
                    $logged[spl_object_id($entity)] = true;
                    $this->queue($entity, null === $entity->trashedAt ? ContactChangeKind::Created : ContactChangeKind::Destroyed);

                    continue;
                }

                if ([] !== array_diff(array_keys($changes), self::IGNORED) && null === $entity->trashedAt) {
                    $logged[spl_object_id($entity)] = true;
                    $this->queue($entity, ContactChangeKind::Updated);
                }
            } elseif ($entity instanceof AddressBook) {
                if ([] !== array_diff(array_keys($uow->getEntityChangeSet($entity)), self::IGNORED_BOOK)) {
                    $this->queueBook($entity, ContactChangeKind::Updated);
                }
            }
        }

        // A child row changing is its contact changing — once per contact,
        // and not at all for one this flush created, removed or binned.
        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $scheduled) {
            foreach ($scheduled as $entity) {
                $contact = $this->ownerOf($entity);

                if (null === $contact || isset($logged[spl_object_id($contact)]) || null !== $contact->trashedAt) {
                    continue;
                }

                $logged[spl_object_id($contact)] = true;
                $this->queue($contact, ContactChangeKind::Updated);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        $rows          = $this->pending;
        $this->pending = [];

        $connection = $args->getObjectManager()->getConnection();
        $now        = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $contactId = $row['contact'] ?? $row['entity']?->id;
            $bookId    = $row['book'] ?? $row['bookEntity']?->id;

            // A contact row whose id never arrived, or a book row without its
            // book, would name nothing a client could fetch.
            if ((null !== $row['entity'] || null !== $row['contact']) ? null === $contactId : null === $bookId) {
                continue;
            }

            $connection->insert('contact_change_log', [
                'user_id'         => $row['user'],
                'contact_id'      => $contactId,
                'address_book_id' => null === $contactId ? $bookId : null,
                'change_kind'     => $row['kind']->value,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    private function queue(Contact $contact, ContactChangeKind $kind, bool $deferId = false): void
    {
        $userId = $contact->usr->id;

        if (null === $userId) {
            return;
        }

        $this->pending[] = [
            'user'       => $userId,
            'contact'    => true === $deferId ? null : $contact->id,
            'entity'     => true === $deferId || null === $contact->id ? $contact : null,
            'book'       => null,
            'bookEntity' => null,
            'kind'       => $kind,
        ];
    }

    /**
     * The id is taken now when there is one: Doctrine clears it once a delete
     * has run, so deferring it would drop exactly the row saying it went.
     */
    private function queueBook(AddressBook $book, ContactChangeKind $kind): void
    {
        $userId = $book->usr->id;

        if (null === $userId) {
            return;
        }

        $this->pending[] = [
            'user'       => $userId,
            'contact'    => null,
            'entity'     => null,
            'book'       => $book->id,
            'bookEntity' => null === $book->id ? $book : null,
            'kind'       => $kind,
        ];
    }

    private function ownerOf(object $entity): ?Contact
    {
        if ($entity instanceof ContactEmail || $entity instanceof ContactPhone || $entity instanceof ContactAddress) {
            return $entity->contact;
        }

        return null;
    }
}
