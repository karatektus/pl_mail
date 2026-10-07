<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\MailboxRepository;

/**
 * Whether an account is still bringing in its mailbox for the first time.
 *
 * WHY ANYTHING NEEDS TO KNOW
 * ──────────────────────────
 * An import and an ordinary day put the same messages through the same
 * pipeline, and until now nothing downstream could tell them apart. They want
 * opposite things. Mail trickling in is mail somebody may be watching arrive:
 * it should be sorted and scraped within seconds, and it is worth holding back
 * for the moment that takes. A mailbox being imported is tens of thousands of
 * messages nobody is watching individually: it should never be in front of the
 * first kind, and holding it back would hide a mailbox behind a model.
 *
 * Two things read this — EnrichmentRouter, to choose a queue, and
 * ClassificationHold, to decide whether to hold — and one more waits on it:
 * ClassificationCatchUp will not start on old mail until the import is over.
 *
 * ONE QUESTION, THREE ANSWERS, because the three providers keep their progress
 * in three places and none of them was written to be asked this:
 *
 *   Gmail  has real backfill state. needsBackfill() is false only once a
 *          listing has found nothing left to fetch — see GmailApiSyncer.
 *   Graph  keeps a delta link per folder, written once that folder has been
 *          enumerated. None at all means no folder has been.
 *   IMAP   has neither, but every folder gets a syncedAt when its first pass
 *          ends, so a sync-enabled folder without one is still importing.
 *
 * IT ERRS TOWARDS "STILL IMPORTING", AND THAT IS THE SAFE SIDE. Gmail settles
 * its backfill up to an hour after the last message lands, and for that hour
 * new mail is treated as import mail: sorted promptly on the shared queue but
 * not held, so it may move tabs once. The opposite mistake — calling an import
 * finished early — would hold a mailbox's worth of mail and queue it as live.
 *
 * A folder discovered later makes an IMAP account "importing" again until that
 * folder has been read, which is correct rather than a quirk: what is about to
 * arrive from it is a folder's history, not new mail.
 */
final readonly class InitialImportState
{
    public function __construct(private MailboxRepository $mailboxes)
    {
    }

    public function isComplete(Account $account): bool
    {
        if (true === $account->isGmail()) {
            return false === $account->needsBackfill();
        }

        if (true === $account->isMicrosoft()) {
            return [] !== $account->graphDeltaLinks;
        }

        // Read from the database rather than off $account->mailboxes: the
        // callers are long-running workers whose entity manager is cleared
        // between messages, and a collection hydrated before a clear answers
        // for folders as they were then.
        return 0 === $this->mailboxes->countAwaitingFirstSync($account);
    }

    /**
     * @param iterable<Account> $accounts
     */
    public function allComplete(iterable $accounts): bool
    {
        $any = false;

        foreach ($accounts as $account) {
            $any = true;

            if (false === $this->isComplete($account)) {
                return false;
            }
        }

        // No accounts is not "finished" — it is nothing to say anything about,
        // and the callers treat true as licence to do more.
        return $any;
    }
}
