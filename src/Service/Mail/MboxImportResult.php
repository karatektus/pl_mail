<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\IngestedMessage;

/**
 * What one mbox import did, and the batch still waiting for the pipeline.
 *
 * Counts rather than a log, because the caller is a console command whose
 * whole job is reporting, and the first few failures are carried so the
 * report can name a file rather than say "some failed". The pending batch is
 * public and mutable on purpose: the importer fills it as it goes and drains
 * it in batches, and making that private would mean the class scheduling its
 * own pipeline runs, which is the importer's decision, not the result's.
 */
final class MboxImportResult
{
    /** @var list<IngestedMessage> the batch awaiting PostIngestPipeline */
    public array $pending = [];

    public int $total            = 0;
    public int $imported         = 0;
    public int $wouldImport      = 0;
    public int $skippedDuplicate = 0;
    public int $failed           = 0;

    /** @var list<string> the first few failure messages, for the report */
    public array $failures = [];

    public function __construct(
        public readonly string $path,
        public readonly string $accountEmail,
        public readonly string $labelRole,
    ) {
    }

    public function recordFailure(string $message): void
    {
        $this->failed++;

        if (count($this->failures) < 5) {
            $this->failures[] = $message;
        }
    }
}
