<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

/**
 * One vCard resource on the server, or the news that one has gone.
 *
 * A null $vcard is a deletion: sync-collection reports a removed member as a
 * 404 on its href and nothing else, and that href is all there is to match a
 * local row by.
 */
final readonly class RemoteCard
{
    private function __construct(
        public string  $href,
        public ?string $etag,
        public ?string $vcard,
    ) {
    }

    public static function present(string $href, ?string $etag, string $vcard): self
    {
        return new self($href, $etag, $vcard);
    }

    public static function deleted(string $href): self
    {
        return new self($href, null, null);
    }

    public function isDeleted(): bool
    {
        return null === $this->vcard;
    }
}
