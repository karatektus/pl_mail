<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Import an uploaded .ics onto a calendar (#672). Dispatched by IcsController
 * once the file has been read as a calendar; ImportCalendarHandler does the
 * work.
 *
 * Only the job id travels: the calendar and the staged file are on the job
 * (`view.calendarId`, `view.file`), and the file in JobUploadStore.
 */
final readonly class ImportCalendarMessage
{
    public function __construct(
        public int $jobId,
    ) {}
}
