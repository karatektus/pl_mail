<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Move the mail wearing a label into the folder that label has just become on
 * one account. The label and the account are on the job (`view.labelId`,
 * `view.accountId`).
 */
readonly class MakeLabelFolderMessage
{
    public function __construct(
        public int $jobId,
    ) {}
}
