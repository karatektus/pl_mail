<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use DateTimeImmutable;

/**
 * A backfill that can say beforehand how many messages it would look at.
 *
 * Admin → Backfills offers the count before the run, so an administrator sees
 * whether a repair has anything to do on this installation before queueing
 * it. Most tasks already count their candidates for the progress bar; this
 * hands the same query to the page.
 *
 * **A count of candidates, not of changes.** Several tasks find the rows a
 * fault could be in and then look — repeated-headers reads every stored IMAP
 * original and changes the few that carry the repeated block — so the number
 * is what the run will read, and the page says so in those words.
 *
 * A separate interface rather than a method every task must have: the tasks
 * that walk every message (recipients, headers, rethread) would answer "all of
 * them", which is a number that says nothing the description does not.
 */
interface CountableBackfillTaskInterface extends BackfillTaskInterface
{
    /**
     * @param DateTimeImmutable|null $since only passed to a task that also implements
     *                                      WindowedBackfillTaskInterface; a table repair
     *                                      always receives null and covers everything
     */
    public function countCandidates(?DateTimeImmutable $since): int;
}
