<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Entity\Mail\Message;
use DateTimeImmutable;

/**
 * A submission that passed every check and is waiting to be handed to the bus.
 *
 * It exists because the dispatch happens after the flush rather than where the
 * checks are — see EmailSubmissionSetMethod::handle() — so the decision and
 * the dispatch are in two places and something has to carry the answer
 * between them: which message, when it may leave, and how long the messenger
 * envelope is held for.
 */
final readonly class QueuedSubmission
{
    public function __construct(
        public Message           $message,
        public DateTimeImmutable $sendAt,
        public int               $delayMs,
    ) {
    }
}
