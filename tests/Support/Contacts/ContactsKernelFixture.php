<?php

declare(strict_types=1);

namespace App\Tests\Support\Contacts;

use App\Domain\Enum\Integration\Provider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Users, synced address books and contacts for the kernel tests of the
 * contacts code, written straight to the database — untracked, so a synced
 * contact is stored as a pull would leave it rather than as an edit waiting to
 * be pushed.
 */
final readonly class ContactsKernelFixture
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactSyncTracker $tracker,
    ) {
    }

    public function user(): User
    {
        $user            = new User();
        $user->email     = 'contacts-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Contacts';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function book(User $user, string $name = 'Contacts', bool $readOnly = false): AddressBook
    {
        $integration           = new Integration($user, Provider::CardDav, 'Home server ' . uniqid('', true));
        $integration->baseUrl  = 'https://dav.example.test/';
        $integration->username = 'alice';
        $integration->secret   = 'app-password';
        $this->em->persist($integration);

        $book             = new AddressBook($user, $integration, 'https://dav.example.test/dav/addressbooks/alice/' . uniqid('', true) . '/', $name);
        $book->isReadOnly = $readOnly;
        $this->em->persist($book);
        $this->em->flush();

        return $book;
    }

    /**
     * A contact as a pull would leave it: in the book, on the server, clean.
     *
     * @param list<string> $emails
     */
    public function contact(User $user, string $name, array $emails = [], ?AddressBook $book = null, bool $onServer = true): Contact
    {
        $contact              = Contact::create($user, $name);
        $contact->addressBook = $book;

        if (null !== $book && true === $onServer) {
            $contact->href  = $book->remoteId . $contact->uid . '.vcf';
            $contact->etag  = '"1"';
            $contact->vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$contact->uid}\r\nFN:{$name}\r\nEND:VCARD\r\n";
        }

        foreach ($emails as $email) {
            ContactEmail::create($contact, $email);
        }

        $this->em->persist($contact);
        $this->tracker->untracked(fn () => $this->em->flush());

        return $contact;
    }
}
