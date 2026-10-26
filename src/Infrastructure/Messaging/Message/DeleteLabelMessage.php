<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Carry out the deletion of a label that is a folder on a mail server.
 *
 * Only the job id travels, as with RunBulkStatusMessage: the label and the
 * destination its mail goes to are on the job (`view.labelId`,
 * `view.destination`), where the indicator and the reaper can see them too.
 *
 * Two phases, because the server half cannot run straight after the first.
 * PHASE_MOVE moves the mail locally and hands the provider moves to the export
 * queue; PHASE_REMOTE then deletes the folders, and only once the server says
 * they are empty — which is a question of when those queued moves have run, so
 * it is asked again, on a delay, until it is true or $attempt runs out.
 */
readonly class DeleteLabelMessage
{
    public const string PHASE_MOVE   = 'move';
    public const string PHASE_REMOTE = 'remote';

    public function __construct(
        public int    $jobId,
        public string $phase = self::PHASE_MOVE,
        public int    $attempt = 0,
    ) {}
}
