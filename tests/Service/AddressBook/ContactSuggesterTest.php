<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook;

use App\Domain\DTO\AddressBook\ContactSuggestion;
use App\Entity\Contacts\Contact as AddressBookContact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Mail\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository as AddressBookContacts;
use App\Repository\Mail\ContactRepository;
use App\Service\AddressBook\ContactSuggester;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One ranked list out of the harvest and the address book.
 *
 * The claims: with no address book the order is exactly the harvest's; an
 * address in both places is one suggestion carrying the address book's name;
 * being in the address book breaks ties and never lifts somebody above an
 * address written to more often; and an address kept in two contacts is still
 * one suggestion. Which rows match what was typed is the repository's SQL,
 * tested against Postgres in ContactRepositoryTest.
 */
final class ContactSuggesterTest extends TestCase
{
    /** @var list<Contact> */
    private array $harvest = [];

    /** @var list<ContactEmail> */
    private array $kept = [];

    public function testWithNoAddressBookTheHarvestOrderStands(): void
    {
        $this->harvested('often@example.com', 'Often', 9, '2026-01-01');
        $this->harvested('rarely@example.com', null, 1, '2026-06-01');

        self::assertSame(['often@example.com', 'rarely@example.com'], $this->emails('example'));
    }

    public function testAnAddressInBothPlacesIsOneSuggestionWithTheAddressBookName(): void
    {
        $this->harvested('ada@example.com', 'ADA L. (via list)', 4, '2026-05-01');
        $this->card('Ada Lovelace', ['ada@example.com']);

        $suggestions = $this->suggest('ada');

        self::assertCount(1, $suggestions);
        self::assertSame('Ada Lovelace', $suggestions[0]->name);
        self::assertSame(4, $suggestions[0]->frequency, 'the harvest signals are kept');
        self::assertTrue($suggestions[0]->inAddressBook);
    }

    public function testBeingInAnAddressBookOnlyBreaksTies(): void
    {
        $this->harvested('mailed@example.com', 'Mailed A Lot', 5, '2026-05-01');
        $this->harvested('typed-once@example.com', null, 0, '2026-05-02');
        $this->card('Never Mailed', ['never@example.com']);

        self::assertSame(
            ['mailed@example.com', 'never@example.com', 'typed-once@example.com'],
            $this->emails('example'),
        );
    }

    public function testAnAddressKeptInTwoContactsIsOneSuggestion(): void
    {
        $this->card('Ada Lovelace', ['ada@example.com']);
        $this->card('Ada (synced)', ['ada@example.com']);

        $suggestions = $this->suggest('ada');

        self::assertCount(1, $suggestions);
        self::assertSame('Ada Lovelace', $suggestions[0]->name, 'the first in name order names it');
    }

    public function testAnAddressBookSuggestionKnowsItsContactForThePhoto(): void
    {
        $contact            = $this->card('Ada Lovelace', ['ada@example.com']);
        $contact->photoPath = 'contacts/1/ada.jpg';

        $suggestion = $this->suggest('ada')[0];

        self::assertTrue($suggestion->hasPhoto);
        self::assertSame($contact->id, $suggestion->contactId);
    }

    public function testTheLimitIsAppliedToTheMergedList(): void
    {
        $this->harvested('a@example.com', null, 3, '2026-01-01');
        $this->harvested('b@example.com', null, 2, '2026-01-01');
        $this->card('Carol', ['c@example.com']);

        self::assertSame(['a@example.com', 'b@example.com'], $this->emails('example', 2));
    }

    public function testABlankQuerySuggestsNothing(): void
    {
        $this->harvested('a@example.com', null, 3, '2026-01-01');

        self::assertSame([], $this->suggest('  '));
    }

    public function testInitialsComeFromTheNameOrTheAddress(): void
    {
        self::assertSame('AL', new ContactSuggestion('a@x.test', 'Ada Lovelace', 0, null, false, true)->initials());
        self::assertSame('A', new ContactSuggestion('ada@x.test', null, 0, null, false, false)->initials());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return list<ContactSuggestion> */
    private function suggest(string $query, int $limit = 8): array
    {
        return new ContactSuggester($this->harvestRepository(), $this->addressBookRepository())
            ->suggest($this->createStub(UserInterface::class), $query, $limit);
    }

    /** @return list<string> */
    private function emails(string $query, int $limit = 8): array
    {
        return array_map(static fn (ContactSuggestion $s): string => $s->email, $this->suggest($query, $limit));
    }

    private function harvested(string $email, ?string $name, int $frequency, string $lastSeen): void
    {
        $contact              = new Contact();
        $contact->email       = $email;
        $contact->displayName = $name;
        $contact->frequency   = $frequency;
        $contact->lastSeenAt  = new DateTimeImmutable($lastSeen);

        $this->harvest[] = $contact;
    }

    /** @param list<string> $emails */
    private function card(string $name, array $emails): AddressBookContact
    {
        $contact = AddressBookContact::create(new User(), $name);

        foreach ($emails as $email) {
            $this->kept[] = ContactEmail::create($contact, $email);
        }

        return $contact;
    }

    /**
     * The harvest's own ranking (frequency, then recency) and its substring
     * match, without Postgres.
     */
    private function harvestRepository(): ContactRepository
    {
        $harvest = $this->harvest;

        return new class($harvest) extends ContactRepository {
            /** @param list<Contact> $harvest */
            public function __construct(private readonly array $harvest)
            {
            }

            public function findForAutocomplete(UserInterface $user, string $query, int $limit = 8): array
            {
                $q       = mb_strtolower(trim($query));
                $matches = array_values(array_filter(
                    $this->harvest,
                    static fn (Contact $c): bool => str_contains((string) $c->email, $q)
                        || str_contains(mb_strtolower((string) $c->displayName), $q),
                ));

                usort($matches, static fn (Contact $a, Contact $b): int => [$b->frequency, $b->lastSeenAt] <=> [$a->frequency, $a->lastSeenAt]);

                return array_slice($matches, 0, $limit);
            }
        };
    }

    /**
     * Every kept address matches, in the order given: matching is the SQL's
     * job, ranking is the suggester's, and this tests the second.
     */
    private function addressBookRepository(): AddressBookContacts
    {
        $kept = $this->kept;

        return new class($kept) extends AddressBookContacts {
            /** @param list<ContactEmail> $kept */
            public function __construct(private readonly array $kept)
            {
            }

            public function findForAutocomplete(UserInterface $user, string $query, int $limit): array
            {
                return array_slice($this->kept, 0, $limit);
            }
        };
    }
}
