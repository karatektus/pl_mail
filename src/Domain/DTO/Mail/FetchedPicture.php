<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * A picture a sender picture lookup brought back, before it is checked,
 * re-encoded and stored (#639).
 *
 * `origin` says who answered — `gravatar`, `libravatar:avatars.example.org`,
 * the BIMI or website URL — and is stored beside the picture. Nothing reads
 * it to decide anything; it exists so that an administrator wondering why a
 * sender wears a strange logo can find out where plMail got it.
 */
final readonly class FetchedPicture
{
    public function __construct(
        public string $body,
        public string $contentType,
        public string $origin,
    ) {}
}
