<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\VCardFields;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Helper\AddressHelper;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Service\AddressBook\CardDav\VCardConverter;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creating, editing and deleting a contact from the contacts page.
 *
 * **Saved here first, sent afterwards.** The vCard is edited and stored, the
 * card is marked pending, and a SyncAddressBookMessage carries it to the
 * server. The person sees their change at once whatever the server is doing,
 * and a server that is down for an hour delays the push rather than losing the
 * edit — the next sweep tries again, because the row is still pending.
 *
 * A read-only book refuses every write here rather than at push time: the
 * form never offers one, so arriving with one is a crafted request, and the
 * honest answer is no.
 */
final readonly class ContactCardEditor
{
    public function __construct(
        private VCardConverter         $converter,
        private EntityManagerInterface $em,
        private MessageBusInterface    $bus,
    ) {
    }

    /**
     * @param list<string> $emails
     * @param list<string> $phones
     */
    public function create(
        AddressBook $book,
        string      $fullName,
        array       $emails,
        array       $phones,
        ?string     $organization,
        ?string     $note,
    ): ContactCard {
        $this->assertWritable($book);

        $card   = new ContactCard($book, $this->converter->newUid());
        $fields = $this->fields($card->uid, $fullName, $emails, $phones, $organization, $note);

        $card->vcard     = $this->converter->write(null, $fields);
        $card->syncState = SyncState::PendingCreate;
        $card->fill($fields);

        $this->em->persist($card);
        $this->em->flush();
        $this->push($book);

        return $card;
    }

    /**
     * @param list<string> $emails
     * @param list<string> $phones
     */
    public function update(
        ContactCard $card,
        string      $fullName,
        array       $emails,
        array       $phones,
        ?string     $organization,
        ?string     $note,
    ): void {
        $this->assertWritable($card->addressBook);

        $fields = $this->fields($card->uid, $fullName, $emails, $phones, $organization, $note);

        $card->vcard = $this->converter->write('' === $card->vcard ? null : $card->vcard, $fields);
        $card->fill($fields);

        // A card that has not reached the server yet is still a create: the
        // push must PUT it with If-None-Match, not If-Match against no etag.
        if (SyncState::PendingCreate !== $card->syncState) {
            $card->syncState = SyncState::PendingUpdate;
        }

        $this->em->flush();
        $this->push($card->addressBook);
    }

    public function delete(ContactCard $card): void
    {
        $this->assertWritable($card->addressBook);

        // Never sent: there is nothing to tell the server, so the row simply goes.
        if (null === $card->href) {
            $this->em->remove($card);
            $this->em->flush();

            return;
        }

        $card->syncState = SyncState::PendingDelete;
        $this->em->flush();
        $this->push($card->addressBook);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<string> $emails
     * @param list<string> $phones
     */
    private function fields(
        string  $uid,
        string  $fullName,
        array   $emails,
        array   $phones,
        ?string $organization,
        ?string $note,
    ): VCardFields {
        $emails = array_values(array_unique(array_map(
            static fn (string $email): string => AddressHelper::email($email),
            $emails,
        )));

        $fullName = trim($fullName);

        // FN is required by every vCard version; a contact saved with only an
        // address is named by it, which is what every phone would show anyway.
        if ('' === $fullName) {
            $fullName = $emails[0] ?? trim((string) $organization);
        }

        $organization = null === $organization || '' === trim($organization) ? null : trim($organization);
        $note         = null === $note || '' === trim($note) ? null : $note;

        return new VCardFields($uid, $fullName, $emails, $phones, $organization, $note);
    }

    private function assertWritable(AddressBook $book): void
    {
        if (true === $book->isReadOnly) {
            throw new LogicException('This address book is read-only.');
        }
    }

    private function push(AddressBook $book): void
    {
        if (null !== $book->id) {
            $this->bus->dispatch(new SyncAddressBookMessage($book->id));
        }
    }
}
