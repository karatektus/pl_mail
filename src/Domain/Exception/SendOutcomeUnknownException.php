<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Enum\Mail\SendFailure;

/**
 * The request went out and its answer was lost: a timeout or a dropped
 * connection after the provider had the mail, or a gateway that answered 502
 * or 504 for it. The mail may have left.
 *
 * Never retried blindly — a blind retry is how a recipient gets it twice.
 * Where the provider can be asked (SentCopyLookupInterface: Gmail, Graph),
 * the next attempt first looks for the Message-ID in Sent and only sends if
 * it is not there. Where it cannot (SMTP), the send fails at once and the
 * draft says the mail may have been sent. See SendMessageHandler.
 */
final class SendOutcomeUnknownException extends SendFailureException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, SendFailure::MayHaveSent, $previous);
    }
}
