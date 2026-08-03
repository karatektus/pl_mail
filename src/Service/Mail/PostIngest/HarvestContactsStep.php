<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Service\HarvestContactsService;
use Psr\Log\LoggerInterface;

/**
 * Learns addresses from the mail that just arrived.
 *
 * The contact list is built out of who writes to this account and who it
 * writes to, and it is what the composer's autocomplete suggests from. Every
 * new message is a chance to learn one.
 *
 * The sync handlers used to queue an account-wide harvest at the end of every
 * run, which re-read the entire mailbox to learn about the handful of messages
 * the run had just brought in. On a real account that was fifty-odd thousand
 * fully-hydrated messages per sync, dragging bodies and tsvectors along to read
 * five address fields — measured at 1,744 runs and five and a half hours of
 * database time. The Gmail batch path already harvested only its own batch;
 * this makes IMAP and the account-level sync do the same, and does it in one
 * place rather than three.
 *
 * Harvesting here rather than through a queued message because the work is now
 * proportional to the batch: a few hundred addresses in memory, already loaded.
 * Dispatching would cost a second round trip to re-read what this already holds.
 *
 * A full sweep still has a use — a newly added account, or rebuilding after the
 * address normalisation changed what a valid address looks like — and that is
 * `app:backfill contacts`, run deliberately rather than after every sync.
 */
final readonly class HarvestContactsStep implements PostIngestStepInterface
{
    public function __construct(
        private HarvestContactsService $harvest,
        private LoggerInterface $logger,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        foreach ($result->accounts as $account) {
            $own = $account->email;

            // Only this account's own messages: a batch can span accounts when
            // one carrier delivers for several, and an address is learned for
            // the user who actually corresponded with it.
            $mine = [];

            foreach ($result->messages as $message) {
                if ($message->account === $account) {
                    $mine[] = $message;
                }
            }

            if ([] === $mine) {
                continue;
            }

            $this->harvest->harvestMessages($account->usr, $mine, (string) $own);

            $this->logger->debug('HarvestContactsStep: batch harvested', [
                'accountId' => $account->id,
                'messages'  => count($mine),
            ]);
        }
    }
}
