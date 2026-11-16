<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\SenderPictureKind;

/**
 * The picture a row or a message header shows for its sender (#639).
 *
 * `reference` is the opaque segment both picture routes take — the web one
 * and the JMAP one — so the page and the API name the same file the same way
 * and only the prefix differs. See SenderPictureReference.
 *
 * `rank` is copied out of the kind because the template writes it into the
 * markup, where the live update compares it: a picture arriving later replaces
 * this one only if it ranks better.
 */
final readonly class SenderPictureView
{
    public int $rank;

    public function __construct(
        public SenderPictureKind $kind,
        public string $reference,
    ) {
        $this->rank = $kind->rank();
    }
}
