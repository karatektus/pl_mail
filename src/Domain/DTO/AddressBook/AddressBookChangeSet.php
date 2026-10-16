<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

/**
 * What one pull learned about an address book — CalendarChangeSet's
 * counterpart, with the same three outcomes.
 *
 * **A listing is authoritative only when it answers a null token.** Asked with
 * a token, the server reports what changed since and the rest is untouched;
 * asked without one, it reports everything that exists, and a local row it did
 * not mention has been deleted there. The sync service reads $isFullListing to
 * know which, rather than inferring it from the token it happened to send.
 *
 * @see \App\Domain\DTO\Calendar\CalendarChangeSet
 */
final readonly class AddressBookChangeSet
{
    /**
     * @param list<RemoteCard> $cards
     */
    private function __construct(
        public array   $cards,
        public ?string $syncToken,
        public bool    $isFullListing,
        public bool    $resyncRequired,
    ) {
    }

    /**
     * @param list<RemoteCard> $cards
     */
    public static function of(array $cards, ?string $syncToken, bool $isFullListing): self
    {
        return new self($cards, $syncToken, $isFullListing, false);
    }

    public static function unchanged(string $syncToken): self
    {
        return new self([], $syncToken, false, false);
    }

    /**
     * The stored position is no good — a dead token, or a ctag that moved on a
     * server that cannot say what changed. The caller drops the token and
     * pulls again from scratch.
     */
    public static function resyncRequired(): self
    {
        return new self([], null, false, true);
    }
}
