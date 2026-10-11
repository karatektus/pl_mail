<?php

declare(strict_types=1);

namespace App\Service\Gmail;

/**
 * What one page of a Gmail backfill listing left to do.
 */
enum GmailBackfillStep
{
    /** There is another page to this listing. Ask for it. */
    case More;

    /**
     * Nothing to list just now: the last listing found mail that is still
     * being fetched, and the next one is not due until that has had its hour.
     */
    case Waiting;

    /** The whole mailbox is in. */
    case Finished;
}
