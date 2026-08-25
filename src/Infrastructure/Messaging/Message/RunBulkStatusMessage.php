<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Carry out a bulk status change that is too large for a request.
 *
 * Only the job id travels. The selection is a VIEW — a scope, a value and
 * whether it was filtered to unread — and resolving it is the handler's job,
 * deliberately: the set is large, it is already stored on the job, and putting
 * five thousand thread ids in an envelope would be a queue row the size of the
 * work it describes.
 *
 * It also means the handler resolves the view as it stands when the work runs
 * rather than when the button was pressed. For "mark this view read" that is
 * the better answer — mail that arrived in between is part of the view the user
 * selected.
 */
readonly class RunBulkStatusMessage
{
    public function __construct(
        public int $jobId,
    ) {}
}
