<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

/**
 * The part of a vCard plMail reads and edits.
 *
 * Deliberately a small subset of RFC 6350 — the fields a mail client has a use
 * for: who someone is, how to write to them, how to call them, where they work
 * and a note. Everything else a card carries (a photo, a postal address, a
 * birthday, X- properties a phone wrote) stays in ContactCard::$vcard and is
 * written back untouched; VCardConverter::write() edits these fields inside the
 * stored card rather than rebuilding it from this object. That is what lets a
 * contact edited here keep its photo on the phone.
 */
final readonly class VCardFields
{
    /**
     * @param list<string> $emails lower-cased addresses, in card order, no
     *                             duplicates
     * @param list<string> $phones as written, in card order, no duplicates
     */
    public function __construct(
        public string  $uid,
        public string  $fullName,
        public array   $emails = [],
        public array   $phones = [],
        public ?string $organization = null,
        public ?string $note = null,
    ) {
    }
}
