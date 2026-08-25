<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * A scripted answer to mail a demo visitor sent, delivered after a delay.
 *
 * Carries the reply's own facts rather than the id of the sent row. The
 * handler runs seconds later in another process, and the alternative — look
 * the message up and read its recipients — would break exactly when a visitor
 * sends and then deletes, which on a demo is a thing people try on purpose.
 */
final readonly class DemoAutoReplyMessage
{
    public function __construct(
        public int     $accountId,
        public ?string $inReplyTo,
        public string  $fromAddress,
        public ?string $fromName,
        public string  $subject,
    ) {
    }
}
