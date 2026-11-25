<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\RunBackfillMessage;
use App\Service\Maintenance\Backfill\BackfillRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a backfill queued from the admin page. All of it is BackfillRunner's;
 * this is the door the maintenance worker comes in by.
 */
#[AsMessageHandler]
final readonly class RunBackfillHandler
{
    public function __construct(
        private BackfillRunner $runner,
    ) {}

    public function __invoke(RunBackfillMessage $message): void
    {
        $this->runner->execute($message->runId);
    }
}
