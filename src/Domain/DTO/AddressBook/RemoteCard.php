<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

use App\Service\Contacts\ParsedContact;

/**
 * One contact on the server, or the news that one has gone.
 *
 * $href is whatever the driver names a contact by — a CardDAV href, a People
 * API resourceName, a Graph contact id — and comes back to it verbatim.
 *
 * Two shapes for a present contact, one per kind of server:
 *
 *   - **A vCard** (CardDAV). The card itself, which AddressBookSync parses and
 *     keeps in Contact::$vcard so a later edit patches it.
 *   - **Already parsed** (Google, Graph). Their drivers translate the
 *     provider's JSON into ParsedContact at their own boundary, as the
 *     calendar drivers translate theirs into JSCalendar, so nothing above the
 *     driver reads a provider's format. $resource is what the driver wants
 *     back on the next write, kept opaque in Contact::$remoteResource.
 *
 * A deletion carries the href and nothing else: that is all a sync-collection
 * 404, a People API tombstone or a Graph @removed says.
 */
final readonly class RemoteCard
{
    private function __construct(
        public string         $href,
        public ?string        $etag,
        public ?string        $vcard,
        public ?ParsedContact $parsed,
        public ?string        $resource,
        public bool           $isDeleted,
    ) {
    }

    public static function present(string $href, ?string $etag, string $vcard): self
    {
        return new self($href, $etag, $vcard, null, null, false);
    }

    public static function parsed(string $href, ?string $etag, ParsedContact $parsed, ?string $resource = null): self
    {
        return new self($href, $etag, null, $parsed, $resource, false);
    }

    public static function deleted(string $href): self
    {
        return new self($href, null, null, null, null, true);
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }
}
