<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Mail\MessageThread;

/**
 * One page of search results and how many there are in total.
 *
 * The two travel together because they are now answered together: the total
 * comes out of the same statement that fetched the page, as a `COUNT(*) OVER ()`
 * over the grouped rows, rather than from a second `SELECT COUNT(DISTINCT …)`
 * that repeated every scan the first one had already paid for — 620ms of a
 * 1.9s page on 300,000 messages, for a number the toolbar prints in a corner.
 *
 * Keeping them in one object is not tidiness. The old pair could disagree:
 * search() re-runs its query with the body-substring rescue when a page comes
 * back thin, and the count never did, so a rescued page could show more rows
 * than the total above it admitted existed. One statement cannot contradict
 * itself.
 */
final readonly class SearchPage
{
    /**
     * @param list<MessageThread> $threads in the order the sort asked for
     * @param int                 $total   matching threads across every page
     */
    public function __construct(
        public array $threads,
        public int $total,
    ) {
    }
}
