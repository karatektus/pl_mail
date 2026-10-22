<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Enum\Mail\SendFailure;

/**
 * A send that did not go through, classified by what it means for the next
 * attempt (#125).
 *
 * The three subclasses are the three answers a sender can give:
 *
 *   SendDeferredException        the mail certainly did not leave, and trying
 *                                again may work — a rate limit, a 5xx, a
 *                                connection that never opened;
 *   SendRefusedException         the mail certainly did not leave, and trying
 *                                again will not change that — a 4xx, an SMTP
 *                                5xx, a message too large;
 *   SendOutcomeUnknownException  the request went out and the answer was lost,
 *                                so the mail may have left.
 *
 * Thrown by MailDeliveryInterface::deliver() only. MailSenderInterface::send()
 * still answers true or false for every other caller — invitations, alerts,
 * read receipts — which retry nothing and need nothing more.
 */
abstract class SendFailureException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly SendFailure $failure,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** What the draft's warning says, should this be the end of it. */
    public function failure(): SendFailure
    {
        return $this->failure;
    }
}
