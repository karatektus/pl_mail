<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Put the conversations of a removed inbox category back into the tabs they
 * belong in (#672). Dispatched by InboxCategoryManager::remove();
 * ResortRemovedCategoryHandler does the work.
 *
 * Only the job id travels: the conversations are on the job
 * (`view.threadIds`), since after the removal nothing in the database says
 * which ones they were.
 */
final readonly class ResortRemovedCategoryMessage
{
    public function __construct(
        public int $jobId,
    ) {}
}
