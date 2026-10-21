<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\ContactReadOnlyException;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Infrastructure\Messaging\Message\DeleteRemoteContactMessage;
use App\Infrastructure\Messaging\Message\PushAddressBookMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Marks a synced contact for pushing whenever anything about it changes, and
 * starts the push.
 *
 * A listener rather than a call every writer makes, for the reason
 * CalendarChangeLogListener gives at length: Contact and its emails, phones
 * and addresses are public mutable properties, and they are written from the
 * contact page, JMAP Contact/set, the merger, the importer and its undo. A
 * writer that forgot to say "this needs pushing" would leave an edit on this
 * side only, until the next pull from the server quietly put the old value
 * back — the failure with no symptom until somebody looks at their phone.
 * Doctrine already knows what changed; asking it is the only complete answer.
 *
 * ── What a change becomes ─────────────────────────────────────────────────
 *
 * - **An edit** (a field, or a child row added, changed or removed) makes the
 *   contact PendingUpdate — or keeps it PendingCreate if it never reached the
 *   server. A new contact in a synced book starts PendingCreate.
 * - **Moving it to the bin** makes it PendingDelete; the push deletes the card
 *   and then lets go of the book, so the contact stays in the bin as one of
 *   plMail's own (see AddressBookSync::push()). A contact that never reached
 *   the server lets go at once.
 * - **Restoring** one still waiting for that delete cancels it.
 * - **Deleting the row** outright — a merge, the bin's purge — cannot be
 *   pushed from the row, which is gone after this flush. The card's address is
 *   kept, and DeleteRemoteContactMessage deletes it once the flush is done.
 *
 * **A new photo** (uploaded or removed here) is an edit like any other, and
 * also sets Contact::$photoDirty, which tells the push to send the photo —
 * it is a request of its own at Google and Outlook, made only when asked.
 *
 * Bookkeeping is not an edit: the sync state, the server's href, etag and
 * card, the photo's flags and the timestamps are ignored, so the sync's
 * own writes do not make a contact pending again. The sync also runs inside
 * untracked(), because applying a pull does change real fields.
 *
 * A change to a contact in a read-only book throws ContactReadOnlyException:
 * every screen and JMAP refuse that first, so reaching here means a path that
 * forgot, and failing the flush is better than an edit the server refuses.
 *
 * onFlush decides, postFlush dispatches: a message sent from onFlush would go
 * out even if the flush then failed. What it dispatches is a push alone
 * (PushAddressBookMessage), on the export queue with everything else leaving
 * plMail; reading the server stays with the sweep. The push is dispatched a
 * couple of seconds late, because one save can be several flushes (JMAP
 * Contact/set writes each address as it goes): a worker that picked it up at
 * once could push the card between two of them, half saved. The flushes after
 * the first dispatch pushes of their own, which find nothing left to send.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ContactSyncTracker
{
    /** Contact fields whose change is not an edit; see the class comment. */
    private const array IGNORED = [
        'createdAt',
        'updatedAt',
        'addressBook',
        'uid',
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

    /** How long a push waits for the rest of the save; see the class comment. */
    private const int SETTLE_MS = 2000;

    private int $suspended = 0;

    /** @var array<int, true> address book ids to push once the flush is done */
    private array $books = [];

    /** @var list<DeleteRemoteContactMessage> */
    private array $deletions = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Run $work without treating what it writes as edits to push. For
     * AddressBookSync, which writes what the server already has.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function untracked(callable $work): mixed
    {
        ++$this->suspended;

        try {
            return $work();
        } finally {
            --$this->suspended;
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Left over from a flush that threw before postFlush: it changed
        // nothing, so there is nothing to push for it.
        $this->books     = [];
        $this->deletions = [];

        if (0 < $this->suspended) {
            return;
        }

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, Contact> $edited */
        $edited = [];
        /** @var array<int, Contact> $created */
        $created = [];
        /** @var array<int, Contact> $binned */
        $binned = [];
        /** @var array<int, Contact> $restored */
        $restored = [];
        /** @var array<int, true> $deleted */
        $deleted = [];

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Contact) {
                $deleted[spl_object_id($entity)] = true;
                $this->queueRemoteDelete($entity);
            }
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Contact) {
                $created[spl_object_id($entity)] = $entity;
            } elseif (null !== ($contact = $this->ownerOf($entity))) {
                $edited[spl_object_id($contact)] = $contact;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Contact) {
                $changes = $uow->getEntityChangeSet($entity);

                if (true === array_key_exists('trashedAt', $changes)) {
                    if (null !== $entity->trashedAt) {
                        $binned[spl_object_id($entity)] = $entity;
                    } else {
                        $restored[spl_object_id($entity)] = $entity;
                    }
                }

                if ([] !== array_diff(array_keys($changes), self::IGNORED)) {
                    $edited[spl_object_id($entity)] = $entity;
                }

                if (true === array_key_exists('photoPath', $changes) && true === $entity->isSynced() && false === $entity->photoDirty) {
                    $entity->photoDirty = true;
                    $this->recompute($uow, $em->getClassMetadata(Contact::class), $entity);
                }
            } elseif (null !== ($contact = $this->ownerOf($entity))) {
                $edited[spl_object_id($contact)] = $contact;
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $contact = $this->ownerOf($entity);

            if (null !== $contact && false === isset($deleted[spl_object_id($contact)])) {
                $edited[spl_object_id($contact)] = $contact;
            }
        }

        foreach ([$created, $edited, $binned, $restored] as $group) {
            foreach ($group as $contact) {
                if (true === $contact->isSynced() && false === $contact->isEditable()) {
                    throw new ContactReadOnlyException(sprintf('Contact %s is in a read-only address book.', $contact->uid));
                }
            }
        }

        foreach ($created as $id => $contact) {
            unset($edited[$id]);

            if (true === $contact->isSynced()) {
                $contact->syncState = SyncState::PendingCreate;
                $this->recompute($uow, $em->getClassMetadata(Contact::class), $contact);
            }
        }

        foreach ($restored as $id => $contact) {
            unset($edited[$id]);

            if (true === $contact->isSynced() && SyncState::PendingDelete === $contact->syncState) {
                $contact->syncState = null === $contact->href ? SyncState::PendingCreate : SyncState::PendingUpdate;
                $this->recompute($uow, $em->getClassMetadata(Contact::class), $contact);
            }
        }

        foreach ($binned as $id => $contact) {
            unset($edited[$id]);

            if (false === $contact->isSynced()) {
                continue;
            }

            if (null === $contact->href) {
                // Never written to the server: nothing to delete there.
                $contact->addressBook = null;
                $contact->syncState   = SyncState::Clean;
                $this->recompute($uow, $em->getClassMetadata(Contact::class), $contact);

                continue;
            }

            $contact->syncState = SyncState::PendingDelete;
            $this->recompute($uow, $em->getClassMetadata(Contact::class), $contact);
        }

        foreach ($edited as $id => $contact) {
            if (false === $contact->isSynced() || true === $contact->isTrashed() || true === isset($deleted[$id])) {
                continue;
            }

            $state = null === $contact->href ? SyncState::PendingCreate : $contact->syncState->afterLocalEdit();

            if ($state !== $contact->syncState) {
                $contact->syncState = $state;
                $this->recompute($uow, $em->getClassMetadata(Contact::class), $contact);
            }

            $this->books[(int) $contact->addressBook?->id] = true;
        }

        foreach ([...$created, ...$restored, ...$binned] as $contact) {
            if (null !== $contact->addressBook?->id && true === $contact->syncState->isPending()) {
                $this->books[$contact->addressBook->id] = true;
            }
        }

        unset($this->books[0]);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $books     = array_keys($this->books);
        $deletions = $this->deletions;

        $this->books     = [];
        $this->deletions = [];

        foreach ($deletions as $message) {
            $this->bus->dispatch($message);
        }

        foreach ($books as $bookId) {
            $this->bus->dispatch(new PushAddressBookMessage($bookId), [new DelayStamp(self::SETTLE_MS)]);
        }
    }

    private function ownerOf(object $entity): ?Contact
    {
        if ($entity instanceof ContactEmail || $entity instanceof ContactPhone || $entity instanceof ContactAddress) {
            return $entity->contact;
        }

        return null;
    }

    /**
     * A contact removed outright: its card goes too, unless it is in the bin
     * (its card was deleted when it went there) or never reached the server.
     */
    private function queueRemoteDelete(Contact $contact): void
    {
        $book = $contact->addressBook;

        if (null === $book?->id || null === $contact->href) {
            return;
        }

        if (true === $book->isReadOnly) {
            throw new ContactReadOnlyException(sprintf('Contact %s is in a read-only address book.', $contact->uid));
        }

        $this->deletions[] = new DeleteRemoteContactMessage($book->id, $contact->href, $contact->etag);
    }

    /**
     * @param ClassMetadata<Contact> $metadata
     */
    private function recompute(UnitOfWork $uow, ClassMetadata $metadata, Contact $contact): void
    {
        $uow->recomputeSingleEntityChangeSet($metadata, $contact);
    }
}
