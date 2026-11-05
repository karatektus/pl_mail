<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Service\Search\SearchIndexRebuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finishes the search index rebuild now, rather than a pass a minute (#477).
 *
 * After Version20261105120000 every message waits to be indexed again, and
 * `app:search:index-pending` gets through them from the schedule, newest
 * first. This is the same work, SearchIndexRebuilder's, without the time
 * budget and with a progress bar: for an administrator who would rather wait
 * at a terminal than have old mail missing from search for an evening. Both
 * at once is fine; they split the rows between them.
 *
 * Named after the half that is new to most readers: it is also what stores
 * the words of mail sent as HTML alone, and drops their headers-only
 * embeddings. Safe to run again; a row it has done is not waiting any more.
 */
final readonly class HtmlBodyTextBackfillTask implements BackfillTaskInterface
{
    public function __construct(
        private SearchIndexRebuilder $rebuilder,
    ) {}

    public function getName(): string
    {
        return 'html-body-text';
    }

    public function getDescription(): string
    {
        return 'Index every message still waiting for search, storing the words of HTML-only mail so search, embeddings and summaries read its body.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->rebuilder->pending();

        if (0 === $total) {
            $io->success('Nothing to index — every message is in the search index.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $done = $this->rebuilder->rebuild(progress: static fn (int $rows) => $io->progressAdvance($rows));

        $io->progressFinish();
        $io->success(sprintf(
            'Indexed %d message(s); stored the words of %d sent as HTML only, whose embeddings are made again by the next index pass.',
            $done['indexed'],
            $done['filled'],
        ));

        return Command::SUCCESS;
    }
}
