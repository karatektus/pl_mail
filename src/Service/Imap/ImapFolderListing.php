<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Mailbox;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * What a folder actually holds right now: its UIDVALIDITY and every UID in it.
 *
 * ## Why this exists at all
 *
 * Incremental sync asks for `lastSeenUid+1:*`, which is what makes a poll of a
 * fifty-thousand-message folder cost nothing — and is also, exactly, why no
 * amount of polling can ever notice a message leaving. A UID below the
 * high-water mark is never looked at again, so a message deleted on the server
 * stayed in plMail forever, and a message *moved* on the server only ever
 * healed as a side effect of the duplicate collapse in SentCopyReconciler,
 * which needs a Message-ID and a second row to work with. Neither is available
 * when mail is simply deleted, and deletion left no trace at all.
 *
 * Learning what left requires asking what is still there. There is no cheaper
 * question.
 *
 * ## Why UID SEARCH ALL and not QRESYNC
 *
 * RFC 7162 is the right answer to this problem and plMail cannot use it:
 * webklex/php-imap implements neither QRESYNC nor CONDSTORE — there is no
 * ENABLE, no MODSEQ parsing, no VANISHED response handling anywhere in the
 * library — so there is no supported path to a server-computed diff. Adding one
 * means implementing a protocol extension inside a vendored client, which is a
 * great deal more code than this and fails closed on every server that does not
 * advertise it anyway.
 *
 * `EXAMINE` followed by `UID SEARCH ALL` is two round trips, universally
 * supported since IMAP4rev1, and hands back a few hundred kilobytes for a
 * folder of fifty thousand. That is too much to do on every poll and nothing at
 * all to do every quarter of an hour, which is why VanishedMessageReconciler
 * puts it on a cadence rather than in the poll.
 *
 * EXAMINE rather than SELECT deliberately: it is the read-only form, so
 * measuring a folder never clears \Recent on it.
 *
 * ## Three answers, again
 *
 * Null means the server did not answer, and null is never evidence of anything
 * — the same discipline ImapUidPresence is built on and for the same reason,
 * since the caller's response to "these UIDs are gone" is eventually to delete
 * mail. A folder that cannot be examined is a folder that gets swept next time.
 */
final readonly class ImapFolderListing
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{uidValidity: int, uids: array<int,true>}|null  null when the
     *         server did not answer. `uids` is a set rather than a list: the
     *         caller's only question of it is membership, once per local row.
     */
    public function read(Client $client, Mailbox $mailbox): ?array
    {
        $path = (string) ($mailbox->fullPath ?? $mailbox->name);

        try {
            $connection = $client->getConnection();

            $examined = $connection->examineFolder($path)->validatedData();

            if (false === is_array($examined) || false === isset($examined['uidvalidity'])) {
                // A server that selects a folder without telling us its
                // UIDVALIDITY has not given us enough to be safe with. Every
                // conclusion below rests on the UIDs being comparable to the
                // ones stored, and that is precisely what UIDVALIDITY asserts.
                $this->logger->info('Folder listing skipped: no UIDVALIDITY in the EXAMINE response', [
                    'mailbox' => $path,
                ]);

                return null;
            }

            $found = $connection->search(['ALL'], IMAP::ST_UID)->validatedData();

            if (false === is_array($found)) {
                return null;
            }

            $uids = [];

            foreach ($found as $uid) {
                $uids[(int) $uid] = true;
            }

            return [
                'uidValidity' => (int) $examined['uidvalidity'],
                'uids'        => $uids,
            ];
        } catch (Throwable $e) {
            $this->logger->info('Could not list a folder in full', [
                'mailbox' => $path,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
