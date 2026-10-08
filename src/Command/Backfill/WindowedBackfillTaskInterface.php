<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use DateTimeImmutable;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A backfill that reads MAIL, and can therefore be told how far back to read.
 *
 * Most tasks repair a table: a column that was computed wrongly is wrong in
 * every row, and half a repair is a table in two states. Those implement
 * BackfillTaskInterface and always walk everything.
 *
 * A task that re-reads mail for what an extractor can now find in it is a
 * different kind of work. Its usual occasion is "a reader was fixed, and the
 * mail I am thinking of arrived this week" — and run over a whole mailbox it
 * answers that by also digging up every calendar file and parcel of the last
 * ten years. So these take a starting point, and BackfillCommand gives them
 * the last 24 hours unless it is told otherwise.
 *
 * A separate interface rather than a parameter on run(): a table repair that
 * accepted a window and ignored it would be a command that looks as though it
 * did what it was asked.
 */
interface WindowedBackfillTaskInterface extends BackfillTaskInterface
{
    /**
     * @param DateTimeImmutable|null $since only mail received at or after this; null for all of it
     */
    public function runSince(SymfonyStyle $io, ?DateTimeImmutable $since): int;
}
