<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

use DateTimeImmutable;

/**
 * One address offered while somebody types a recipient — from the mail
 * plMail has seen, from an address book, or from both.
 *
 * The harvested ranking signals are kept as they were (frequency, lastSeenAt,
 * isCorrespondent) so the two surfaces that show this — the web composer and
 * JMAP Contact/autocomplete — can go on explaining the order they were given.
 * $inAddressBook is the one new fact: the person put this address in their
 * contacts on purpose.
 */
final readonly class ContactSuggestion
{
    public function __construct(
        public string             $email,
        public ?string            $name,
        public int                $frequency,
        public ?DateTimeImmutable $lastSeenAt,
        public bool               $isCorrespondent,
        public bool               $inAddressBook,
    ) {
    }

    /** Two letters for the avatar chip — the rule Contact::$initials uses. */
    public function initials(): string
    {
        $name = trim((string) $this->name);

        if ('' !== $name) {
            $parts = preg_split('/\s+/', $name) ?: [];

            if (count($parts) >= 2) {
                return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
            }

            return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 2));
        }

        return mb_strtoupper(mb_substr($this->email, 0, 1));
    }
}
