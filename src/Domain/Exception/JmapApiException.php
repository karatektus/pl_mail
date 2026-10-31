<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * A JMAP server answered, and the answer was a refusal.
 *
 * One class for both places JMAP can refuse — the HTTP response around a
 * request, and an `error` invocation inside one — because every caller wants
 * the same two facts out of either: whether it is worth asking again, and what
 * the server called it. The HTTP status says the first for a transport
 * refusal; the JMAP error type (`cannotCalculateChanges`, `notFound`,
 * `forbidden`) says it for a method one. A method error carries status 0,
 * which no HTTP response has, so the two cannot be confused.
 *
 * Modelled on GraphApiException rather than on Pop3Exception: the callers are
 * Messenger handlers that already know how to read a status off an exception
 * and decide on a retry, and a JMAP account belongs on that road.
 */
class JmapApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int     $status = 0,
        private readonly ?string $type = null,
        ?\Throwable              $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The JMAP error type, from a method error or a problem-details body.
     *
     * Kept as the server's string rather than an enum: RFC 8620 and RFC 8621
     * between them define more than twenty, servers add their own, and the
     * callers compare against the two or three they act on.
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Whether the credentials were refused, as opposed to anything else.
     *
     * 401 and nothing more. A 403 from a JMAP server is usually a capability
     * the account does not have — a read-only shared account, a submission
     * the server will not allow — and treating it as a bad password would put
     * the "sign in again" card on an account whose password is fine.
     */
    public function isAuthenticationFailure(): bool
    {
        return 401 === $this->status;
    }
}
