<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Bring in the next page of an account's history, and ask again if there is
 * more.
 *
 * Dispatched by MailImporter — once to start an account's import, and then by
 * the handler of each page for the page after it. That chain is the import:
 * there is no long job anywhere in it, only a succession of short ones, which
 * is what lets several accounts import side by side (their pages interleave on
 * the queue in arrival order) and lets the worker's heartbeat stay fresh.
 *
 * It carries the account and nothing else. Where the import has got to is on
 * the account and its folders, not in the envelope: a page that is lost to a
 * worker restart is then asked for again by the next message, whichever that
 * is, instead of being skipped by one that thought it knew what came next.
 */
readonly class ImportMailMessage
{
    public function __construct(
        public int $accountId,
    ) {}
}
