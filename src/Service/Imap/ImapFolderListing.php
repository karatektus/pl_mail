<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Mail\Mailbox;
use App\Infrastructure\ImapClient\ImapSession;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * ## Why the listing carries flags now
 *
 * This used to ask `UID SEARCH ALL`, which answers presence and nothing else —
 * about seven bytes a message. It now asks `UID FETCH 1:* (FLAGS)`, which
 * answers presence *and* every message's flags in the same round trip, at
 * roughly forty to sixty bytes a message.
 *
 * That looks like paying six times more for something only half of which the
 * sweep wanted, and it is the opposite. plMail never re-read the flags of a
 * stored message — they were captured once at ingest and never again, so mail
 * read on a phone stayed unread here forever — and fixing that needs a
 * full-folder flag read on a cadence no matter what. Done separately it would
 * be `UID SEARCH ALL` *plus* `UID FETCH 1:* (FLAGS)`: this same FETCH, plus the
 * SEARCH still underneath it. Folding the two together is therefore strictly
 * cheaper than the alternative that keeps them apart, not more expensive, and
 * it is one command per folder per cadence instead of two.
 *
 * The consistency argument is the better one anyway. Two commands are two
 * instants, and a message can vanish between them — which would have the sweep
 * reasoning about a folder the flag pass never saw. One FETCH gives both
 * answers from one instant, so "what is here" and "what state is it in" cannot
 * disagree.
 *
 * The cost that is real is memory rather than bytes: the whole response is
 * read before it is returned, so a fifty-thousand-message folder builds a
 * fifty-thousand-entry map. That is tens of megabytes at the top end and it is
 * the honest reason a bigger installation might want this chunked. It is not
 * chunked here because chunking needs UID ranges to chunk *by*, and learning
 * the ranges is the very question this command answers.
 *
 * ## Why UID FETCH and not QRESYNC
 *
 * RFC 7162 is the right answer to this problem, and it fails closed on every
 * server that does not advertise it, which many do not. It was out of reach
 * while IMAP went through webklex, which implements neither QRESYNC nor
 * CONDSTORE; plMail's own client could learn it, and this would remain the
 * path for the servers without it.
 *
 * `EXAMINE` followed by `UID FETCH 1:* (FLAGS)` is two round trips, universally
 * supported since IMAP4rev1, and hands back a couple of megabytes for a folder
 * of fifty thousand. That is too much to do on every poll and little enough to
 * do every quarter of an hour, which is why VanishedMessageReconciler puts it
 * on a cadence rather than in the poll.
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
     * @return array{uidValidity: int, uids: array<int,true>, flags: array<int,list<string>>, sizes: array<int,int>, readAt: \DateTimeImmutable}|null
     *         null when the server did not answer. `uids` is a set rather than a
     *         list: the caller's only question of it is membership, once per
     *         local row. `flags` is keyed by the same UIDs, and `readAt` is when
     *         the question was put to the server — which the flag reconciler
     *         needs, because "what the server said" is only meaningful together
     *         with "and when". `sizes` is each message's RFC822.SIZE, a few
     *         bytes a message, which is how mail stored before #415 is given
     *         the size the sync recognises its copies by (see
     *         MessageRepository::backfillSizes()).
     */
    public function read(ImapSession $session, Mailbox $mailbox): ?array
    {
        $path = $mailbox->serverPath();

        try {
            $examined = $session->examine($path);

            if (null === $examined->uidValidity) {
                // A server that selects a folder without telling us its
                // UIDVALIDITY has not given us enough to be safe with. Every
                // conclusion below rests on the UIDs being comparable to the
                // ones stored, and that is precisely what UIDVALIDITY asserts.
                $this->logger->info('Folder listing skipped: no UIDVALIDITY in the EXAMINE response', [
                    'mailbox' => $path,
                ]);

                return null;
            }

            // Stamped before the command rather than after it. The reconciler
            // compares this against local flag changes, and the safe direction
            // to be wrong in is "this answer is older than it really is": that
            // makes a race resolve in favour of the local write, which is the
            // one the user made.
            $readAt = new \DateTimeImmutable();

            // An empty folder answers OK and nothing, which is the listing of
            // a folder with no messages — a fact, and the commonest one a
            // Trash or a Junk folder ever gives. Whether to BELIEVE an empty
            // listing is the sweep's decision, with its bulk guard.
            $found = 0 === $examined->exists
                ? []
                : $session->uidFetch($path, '1:*', ['FLAGS', 'RFC822.SIZE']);

            $uids  = [];
            $flags = [];
            $sizes = [];

            foreach ($found as $uid => $items) {
                $uids[$uid] = true;

                $size = $items['RFC822.SIZE'] ?? null;

                if (true === is_string($size) && 1 === preg_match('~^\d+$~', $size)) {
                    $sizes[$uid] = (int) $size;
                }

                // A message with no flags at all answers with an empty list,
                // which is a fact — "this message is unread and unstarred" —
                // and not a missing answer. Anything that is not a list is the
                // missing answer, and it is left out rather than read as empty.
                if (false === is_array($items['FLAGS'] ?? null)) {
                    continue;
                }

                $flags[$uid] = array_values(array_filter($items['FLAGS'], is_string(...)));
            }

            return [
                'uidValidity' => $examined->uidValidity,
                'uids'        => $uids,
                'flags'       => $flags,
                'sizes'       => $sizes,
                'readAt'      => $readAt,
            ];
        } catch (Throwable $e) {
            $this->logger->log(ThrowableSeverity::level($e), 'Could not list a folder in full', [
                'mailbox'   => $path,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
