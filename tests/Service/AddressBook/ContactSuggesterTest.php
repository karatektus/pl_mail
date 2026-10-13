<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook;

use App\Domain\DTO\AddressBook\ContactSuggestion;
use App\Domain\DTO\AddressBook\VCardFields;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use App\Entity\Mail\Contact;
use App\Repository\AddressBook\ContactCardRepository;
use App\Repository\Mail\ContactRepository;
use App\Service\AddressBook\ContactSuggester;
use App\Tests\Service\AddressBook\CardDav\CardDavFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One ranked list out of the harvest and the address books.
 *
 * The claims: with no address book the order is exactly the harvest's; an
 * address in both places is one suggestion carrying the address book's name;
 * being in an address book breaks ties and never lifts somebody above an
 * address written to more often; and a name match offers every address on the
 * card, an address match only that address.
 */
final class ContactSuggesterTest extends TestCase
{
    /** @var list<Contact> */
    private array $harvest = [];

    /** @var list<ContactCard> */
    private array $cards = [];

    private AddressBook $book;

    protected function setUp(): void
    {
        $this->book = CardDavFixture::book();
    }

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

    public function testANameMatchOffersEveryAddressOnTheCard(): void
    {
        $this->card('Grace Hopper', ['grace@navy.example', 'gh@home.example']);

        self::assertSame(['gh@home.example', 'grace@navy.example'], $this->emails('grace'));
    }

    public function testAnAddressMatchOffersOnlyThatAddress(): void
    {
        $this->card('Grace Hopper', ['grace@navy.example', 'gh@home.example']);

        self::assertSame(['gh@home.example'], $this->emails('home.example'));
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
        return new ContactSuggester($this->harvestRepository(), $this->cardRepository())
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
    private function card(string $name, array $emails): void
    {
        $card = new ContactCard($this->book, bin2hex(random_bytes(4)));
        $card->fill(new VCardFields($card->uid, $name, $emails));

        $this->cards[] = $card;
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

    private function cardRepository(): ContactCardRepository
    {
        $cards = $this->cards;

        return new class($cards) extends ContactCardRepository {
            /** @param list<ContactCard> $cards */
            public function __construct(private readonly array $cards)
            {
            }

            public function search(UserInterface $user, string $query = '', int $limit = 200, ?AddressBook $book = null): array
            {
                $q = mb_strtolower(trim($query));

                return array_slice(array_values(array_filter(
                    $this->cards,
                    static fn (ContactCard $c): bool => str_contains($c->searchText, $q),
                )), 0, $limit);
            }
        };
    }
}
