<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Turn a label that is a folder on one account back into a tag there. What it
 * acts on is on the job (`view.labelId`, `view.accountId`, `view.destinationId`).
 *
 * Two phases for DeleteLabelMessage's reason: the folder can only go once the
 * server says the queued moves have emptied it.
 */
readonly class MakeLabelTagMessage
{
    public const string PHASE_MOVE   = 'move';
    public const string PHASE_REMOTE = 'remote';

    public function __construct(
        public int    $jobId,
        public string $phase = self::PHASE_MOVE,
        public int    $attempt = 0,
    ) {}
}
