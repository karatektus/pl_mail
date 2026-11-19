<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Remove a mail account and everything synced for it (#672). Dispatched by
 * AccountRemoval::start(); DeleteAccountHandler does the work.
 *
 * Only the job id travels, as with DeleteLabelMessage: the account is on the
 * job (`view.accountId`), where the indicator, the settings list and the
 * reaper can see it too.
 */
final readonly class DeleteAccountMessage
{
    public function __construct(
        public int $jobId,
    ) {}
}
