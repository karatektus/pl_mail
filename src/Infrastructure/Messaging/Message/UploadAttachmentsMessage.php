<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Send one message's attachments to an integration service.
 *
 * Dispatched by the saveToIntegration rule action. It exists because the rule
 * engine runs inside the sync hot loop — once per rule per batch of newly
 * arrived mail — and uploading a file there would put an HTTP round trip per
 * attachment on the path that fetches mail. A slow or unreachable service
 * would stall the sync rather than just failing its own job.
 *
 * Ids, not entities: the envelope may sit in the queue for a while, and the
 * handler should act on what is true when it runs.
 */
final readonly class UploadAttachmentsMessage
{
    public function __construct(
        public int     $messageId,
        public int     $integrationId,
        public ?string $folder = null,
    ) {
    }
}
