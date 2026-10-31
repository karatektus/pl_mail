<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The address book a demo starts with.
 *
 * DemoMailbox already seeds "contacts", but those are the harvested addresses
 * compose autocompletes from, a different table. The Contacts page reads the
 * address book, so a visitor who opened it found nothing at all, on the feature
 * with the most fields to show.
 *
 * Beside DemoMailbox rather than inside it, for the reason DemoTemplates gives:
 * these go through a service of their own (ContactManager) and share nothing
 * with the mailbox but their callers.
 *
 * Most of them are the people who write in the seeded mail, at the same
 * addresses, so a sender in the inbox and a card in the book are one person. Two
 * are not in the mail at all, because an address book that only repeats the
 * inbox looks generated from it.
 *
 * Fictional throughout, as DemoMailbox promises: the mail domains resolve
 * nowhere, and the phone numbers are from the ranges set aside for fiction
 * (Germany's 030 23125 and 0152 28895, Britain's 020 7946 0).
 */
final readonly class DemoContacts
{
    /**
     * @var list<array{
     *     card: array<string, mixed>,
     *     emails: list<array{0: string, 1: string}>,
     *     phones?: list<array{0: string, 1: string}>,
     *     address?: array<string, string>
     * }>
     *
     * `card` is what ContactManager::create() takes. Emails and phones are
     * [value, label], and the first of each is the primary one.
     */
    private const array CONTACTS = [
        [
            'card' => [
                'displayName' => 'Priya Raman', 'givenName' => 'Priya', 'familyName' => 'Raman',
                'company'     => 'Raman Innenausbau', 'jobTitle' => 'Project manager',
                'birthday'    => '1986-03-14',
                'urls'        => [['label' => 'work', 'value' => 'https://raman-innenausbau.example']],
                'notes'       => 'Prefers a call before anything is ordered.',
                'tags'        => ['Work'],
            ],
            'emails' => [['priya.raman@example.com', 'work']],
            'phones' => [['+49 152 28895 456', 'mobile'], ['+49 30 23125 010', 'work']],
        ],
        [
            'card' => [
                'displayName' => 'Mum', 'givenName' => 'Ruth', 'familyName' => 'Hale',
                'birthday'    => '--06-02',
                'relations'   => [['label' => 'mother', 'value' => 'Ruth Hale']],
                'tags'        => ['Family'],
            ],
            'emails'  => [['ruth@example.org', 'home']],
            'phones'  => [['+44 20 7946 0123', 'home']],
            'address' => ['label' => 'home', 'street' => '14 Orchard Row', 'city' => 'London', 'postcode' => 'N1 9XX', 'country' => 'United Kingdom'],
        ],
        [
            'card' => [
                'displayName' => 'Jonas Weber', 'givenName' => 'Jonas', 'familyName' => 'Weber',
                'nickname'    => 'Jo', 'birthday' => '1990-11-23',
                'tags'        => ['Friends'],
            ],
            'emails' => [['jonas.weber@example.com', 'home']],
            'phones' => [['+49 152 28895 457', 'mobile']],
        ],
        [
            'card' => [
                'displayName' => 'Sofia Marchetti', 'givenName' => 'Sofia', 'familyName' => 'Marchetti',
                'company'     => 'Studio Marchetti', 'jobTitle' => 'Architect',
                'tags'        => ['Work', 'Friends'],
            ],
            'emails' => [['sofia@example.net', 'work']],
            'phones' => [['+49 30 23125 011', 'work']],
        ],
        [
            'card' => [
                'displayName' => 'Marek Nowak', 'givenName' => 'Marek', 'familyName' => 'Nowak',
                'company'     => 'Nowak Schreinerei', 'jobTitle' => 'Carpenter',
                'notes'       => 'Delivers oak himself. Mornings only.',
                'tags'        => ['Work'],
            ],
            'emails'  => [['marek@nowak-schreinerei.example', 'work']],
            'phones'  => [['+49 30 23125 012', 'work']],
            'address' => ['label' => 'work', 'street' => 'Hobelweg 3', 'city' => 'Berlin', 'postcode' => '12059', 'country' => 'Germany'],
        ],
        [
            'card' => [
                'displayName' => 'Steuerbüro Lang', 'company' => 'Steuerbüro Lang',
                'tags'        => ['Work'],
            ],
            'emails' => [['buchhaltung@lang-steuer.example', 'work']],
            'phones' => [['+49 30 23125 013', 'work']],
        ],
        [
            'card' => [
                'displayName' => 'Hotel Aare', 'company' => 'Hotel Aare',
                'urls'        => [['label' => 'work', 'value' => 'https://hotelaare.example']],
                'tags'        => ['Travel'],
            ],
            'emails' => [['reservations@hotelaare.example', 'work']],
        ],
        [
            'card' => [
                'displayName' => 'Elena Fischer', 'givenName' => 'Elena', 'familyName' => 'Fischer',
                'birthday'    => '1988-08-30',
                'tags'        => ['Friends'],
            ],
            'emails' => [['elena.fischer@example.org', 'home'], ['e.fischer@example.net', 'work']],
            'phones' => [['+49 152 28895 458', 'mobile']],
        ],
        [
            'card' => [
                'displayName' => 'Tom Whitfield', 'givenName' => 'Tom', 'familyName' => 'Whitfield',
                'company'     => 'The Long Field', 'department' => 'Editorial', 'jobTitle' => 'Editor',
                'tags'        => ['Work'],
            ],
            'emails' => [['tom@longfield.example', 'work']],
            'phones' => [['+44 20 7946 0124', 'work']],
        ],
    ];

    public function __construct(
        private ContactManager         $contacts,
        private ContactRepository      $contactRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Replaces the user's address book with the demo's.
     *
     * @return int how many contacts were written
     */
    public function seed(User $user): int
    {
        foreach ($this->contactRepository->findBy(['usr' => $user]) as $contact) {
            $this->entityManager->remove($contact);
        }

        $this->entityManager->flush();

        foreach (self::CONTACTS as $entry) {
            $contact = $this->contacts->create($user, ['source' => 'demo'] + $entry['card']);

            foreach ($entry['emails'] as $index => [$email, $label]) {
                $this->contacts->addEmail($contact, $email, $label, 0 === $index);
            }

            foreach ($entry['phones'] ?? [] as $index => [$phone, $label]) {
                $this->contacts->addPhone($contact, $phone, $label, 0 === $index);
            }

            if (true === isset($entry['address'])) {
                $this->contacts->addAddress($contact, $entry['address']);
            }
        }

        return \count(self::CONTACTS);
    }
}
