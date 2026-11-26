<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Import a confirmed contacts file (#672). Dispatched by ContactController
 * once the preview is confirmed; ImportContactsHandler does the work.
 *
 * Only the job id travels: the staged file's name and format are on the job
 * (`view.file`, `view.format`), and the file in JobUploadStore.
 */
final readonly class ImportContactsMessage
{
    public function __construct(
        public int $jobId,
    ) {}
}
