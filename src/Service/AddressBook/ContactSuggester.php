<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\ContactSuggestion;
use App\Entity\AddressBook\ContactCard;
use App\Entity\Mail\Contact;
use App\Repository\AddressBook\ContactCardRepository;
use App\Repository\Mail\ContactRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The recipients to suggest: the addresses harvested from mail, and the ones
 * in the person's address books, as one ranked list.
 *
 * Both surfaces that suggest recipients read this — the web composer's route
 * and JMAP Contact/autocomplete — for the reason ContactRepository's ranking
 * was shared between them before: two rankings would make the suggestion order
 * depend on which device somebody was composing from.
 *
 * ── The ranking ───────────────────────────────────────────────────────────
 *
 *   1. how often the address has been seen in mail (the harvest's frequency);
 *   2. then whether it is in an address book;
 *   3. then how recently it was seen, then the name.
 *
 * So somebody mailed often still comes first, as before; an address-book
 * contact never mailed beats a placeholder typed into a draft once (frequency
 * 0 as well, see ContactRepository::createUnsent()); and with no address book
 * at all the order is exactly the harvest's. Being in an address book only
 * ever breaks a tie — it never lifts somebody above an address the person
 * actually writes to more often. An address in both places
 * is one suggestion, carrying the address book's name — the one the person
 * wrote down, rather than whatever a sender's mail client put in a header.
 *
 * A card matches when its name, organisation or one of its addresses contains
 * what was typed. When the *name* matched, every address on the card is
 * offered — typing "ada" should offer Ada's work and home addresses both;
 * when only an address matched, only that address is.
 *
 * Each side is asked for its own top $limit and the two are merged, which is
 * one bounded query each per keystroke rather than a join of the harvest
 * against every card. It is exact for what matters most — the harvest side is
 * already in the merged order, so no frequently-mailed address is lost — and
 * close for the rest: the cards come back in name order, so a card past the
 * first $limit matches can be missed even when the harvest knows its address,
 * if the harvest's own match on that query did not find it. Only cards that
 * carry an address are asked for (ContactCardRepository::findForAutocomplete),
 * or number-only cards would use up the limit and offer nothing.
 */
final readonly class ContactSuggester
{
    public function __construct(
        private ContactRepository     $harvested,
        private ContactCardRepository $cards,
    ) {
    }

    /**
     * @return list<ContactSuggestion>
     */
    public function suggest(UserInterface $user, string $query, int $limit = 8): array
    {
        $query = trim($query);

        if ('' === $query || $limit < 1) {
            return [];
        }

        /** @var array<string, ContactSuggestion> $byEmail */
        $byEmail = [];

        foreach ($this->harvested->findForAutocomplete($user, $query, $limit) as $contact) {
            $byEmail[mb_strtolower((string) $contact->email)] = $this->fromHarvest($contact);
        }

        $needle = mb_strtolower($query);

        foreach ($this->cards->findForAutocomplete($user, $query, $limit) as $card) {
            foreach ($this->matchingEmails($card, $needle) as $email) {
                $known = $byEmail[$email] ?? null;

                $byEmail[$email] = new ContactSuggestion(
                    email:           $email,
                    name:            '' !== $card->fullName ? $card->fullName : $known?->name,
                    frequency:       $known->frequency ?? 0,
                    lastSeenAt:      $known?->lastSeenAt,
                    isCorrespondent: $known->isCorrespondent ?? false,
                    inAddressBook:   true,
                );
            }
        }

        $suggestions = array_values($byEmail);

        usort($suggestions, static fn (ContactSuggestion $a, ContactSuggestion $b): int => [
            $b->frequency,
            $b->inAddressBook,
            $b->lastSeenAt?->getTimestamp() ?? 0,
            mb_strtolower((string) $a->name),
            $a->email,
        ] <=> [
            $a->frequency,
            $a->inAddressBook,
            $a->lastSeenAt?->getTimestamp() ?? 0,
            mb_strtolower((string) $b->name),
            $b->email,
        ]);

        return array_slice($suggestions, 0, $limit);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function fromHarvest(Contact $contact): ContactSuggestion
    {
        $name = $contact->displayName;

        return new ContactSuggestion(
            email:           (string) $contact->email,
            name:            null === $name || '' === $name ? null : $name,
            frequency:       $contact->frequency,
            lastSeenAt:      $contact->lastSeenAt,
            isCorrespondent: $contact->isCorrespondent,
            inAddressBook:   false,
        );
    }

    /**
     * @return list<string>
     */
    private function matchingEmails(ContactCard $card, string $needle): array
    {
        $byName = str_contains(mb_strtolower($card->fullName), $needle)
            || str_contains(mb_strtolower((string) $card->organization), $needle);

        if (true === $byName) {
            return $card->emails;
        }

        return array_values(array_filter(
            $card->emails,
            static fn (string $email): bool => str_contains($email, $needle),
        ));
    }
}
