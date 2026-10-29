<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * What a server says about one copy before its body is fetched: enough to
 * tell whether it is an email already stored here (#415). See ImapCopies.
 */
final readonly class ImapCopyIdentity
{
    public function __construct(
        public int     $uid,
        public ?int    $size,
        /** Normalised, without angle brackets; '' when the copy has none. */
        public string  $messageId,
        /** X-GM-MSGID or EMAILID, where the server offers one. */
        public ?string $serverId,
        /**
         * Canonical, or null when the server did not say.
         *
         * @var list<string>|null
         */
        public ?array  $flags = null,
    ) {
    }
}
