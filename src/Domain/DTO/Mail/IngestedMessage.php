<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * One freshly built, already persisted Message on its way through
 * PostIngestPipeline.
 *
 * Carries the owning account rather than letting the pipeline read it off the
 * message, because the two are not always the same one: under Gmailify a Gmail
 * account fetches mail addressed to a sibling, and threading and JMAP state
 * must be recorded against the sibling that owns the address, not the account
 * that carried the bytes.
 *
 * rawSource is the original RFC822 bytes when the caller already has them.
 * IMAP does — webklex keeps them after parsing — so they are written to disk in
 * the pipeline, once the row has an id. Gmail and Graph would need a second API
 * call, so they pass null and RawMessageResolver fetches lazily on first use.
 */
final readonly class IngestedMessage
{
    public function __construct(
        public Message  $message,
        public Account  $account,
        public ?string  $rawSource = null,
    ) {
    }
}
