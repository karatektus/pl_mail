<?php

declare(strict_types=1);

namespace App\Service\Imap;

use Webklex\PHPIMAP\Query\Query;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * A search fetched a page at a time, in which a message the library cannot
 * read costs that message and not the folder.
 *
 * The library has a loop for this, Query::chunked(), and the syncer used it.
 * It has two properties that between them let one message stop a folder for
 * good (#45):
 *
 *   - **It gives up the whole fetch for one message.** A message whose MIME
 *     structure the parser will not take throws while the page is being built,
 *     and with the library's default the exception leaves the loop whole. The
 *     page is lost, the pages after it are never asked for, and the syncer's
 *     own retry-then-skip (MessageSyncer::holdForRetry()) never hears of the
 *     message, because it is only ever handed messages that were built. The
 *     mark does not move, so the next sync asks for the same range and dies at
 *     the same place, every poll, for as long as the message exists.
 *
 *   - **Its soft-fail setting cannot be used to fix that.** With soft-fail on,
 *     the unreadable message is dropped and the page comes back — but the loop
 *     runs until the messages it has HANDED OVER add up to the messages the
 *     search found, and one dropped message means they never do. It then asks
 *     for page after empty page without end.
 *
 * So the pages are counted here, from the search, which is a number that does
 * not depend on how many messages survived. Soft-fail is on, and what it
 * dropped on each page is handed over beside the page, keyed by UID, so the
 * caller can hold each for a retry like any other message it could not store.
 *
 * What still ends the fetch is what should: the server going away. That is not
 * a property of one message, and trying the next page would only fail again.
 */
final readonly class ImapPagedFetch
{
    /**
     * @return iterable<array{MessageCollection, array<int, \Throwable>}> each
     *         page as the library built it, and the messages of that page it
     *         could not build: UID => what was thrown
     */
    public function pages(Query $query, int $pageSize): iterable
    {
        $query->setSoftFail(true);

        $found = $query->search();
        $pages = (int) ceil($found->count() / $pageSize);

        // The library keeps every failure of a query in one list, so each page
        // is handed only what was added to it since the page before.
        $reported = [];

        for ($page = 1; $page <= $pages; ++$page) {
            $batch = $query->limit($pageSize, $page)->curate_messages($found);

            $unreadable = array_diff_key($query->errors(), $reported);
            $reported  += $unreadable;

            yield [$batch, $unreadable];
        }
    }
}
