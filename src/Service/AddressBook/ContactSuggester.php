<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\DTO\AddressBook\ContactSuggestion;
use App\Entity\Mail\Contact as HarvestedAddress;
use App\Repository\Contacts\ContactRepository as AddressBookContacts;
use App\Repository\Mail\ContactRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The recipients to suggest: the addresses harvested from mail, and the ones
 * in the person's address book (plMail's own contacts and every synced book),
 * as one ranked list.
 *
 * The two are read side by side and merged here, not copied into one table:
 * the harvest is a statistic per address — how often, how recently — and the
 * address book is people. Mirroring one into the other would have to be kept
 * in step on every edit and every delete, and a contact deleted from the book
 * would linger in the suggestions for as long as the mirror missed it.
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
 * A contact matches when one of its names, its company or one of its addresses
 * contains what was typed, and each matching address is a suggestion of its
 * own — typing "ada" offers Ada's work and home addresses both
 * (ContactRepository::findForAutocomplete()).
 *
 * Each side is asked for its own top $limit and the two are merged, which is
 * one bounded query each per keystroke rather than a join of the harvest
 * against every contact. It is exact for what matters most — the harvest side
 * is already in the merged order, so no frequently-mailed address is lost —
 * and close for the rest: the address book comes back in name order, so a
 * contact past the first $limit matches can be missed even when the harvest
 * knows its address, if the harvest's own match on that query did not find
 * it.
 */
final readonly class ContactSuggester
{
    public function __construct(
        private ContactRepository   $harvested,
        private AddressBookContacts $addressBook,
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

        foreach ($this->addressBook->findForAutocomplete($user, $query, $limit) as $row) {
            $email   = $row->email;
            $contact = $row->contact;
            $known   = $byEmail[$email] ?? null;

            // An address in two contacts (a synced book and plMail's own) is
            // still one suggestion; the first, in name order, names it.
            if (true === $known?->inAddressBook) {
                continue;
            }

            $byEmail[$email] = new ContactSuggestion(
                email:           $email,
                name:            '' !== trim($contact->displayName) ? $contact->displayName : $known?->name,
                frequency:       $known->frequency ?? 0,
                lastSeenAt:      $known?->lastSeenAt,
                isCorrespondent: $known->isCorrespondent ?? false,
                inAddressBook:   true,
                contactId:       $contact->id,
                hasPhoto:        null !== $contact->photoPath && '' !== $contact->photoPath,
            );
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

    private function fromHarvest(HarvestedAddress $contact): ContactSuggestion
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
}
