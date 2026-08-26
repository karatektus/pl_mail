<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Mail\Mailbox;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
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
 * The cost that is real is memory rather than bytes: webklex accumulates the
 * whole response into an array before returning it, so a fifty-thousand-message
 * folder builds a fifty-thousand-entry map. That is tens of megabytes at the
 * top end and it is the honest reason a bigger installation might want this
 * chunked. It is not chunked here because chunking needs UID ranges to chunk
 * *by*, and learning the ranges is the very question this command answers.
 *
 * ## Why UID FETCH and not QRESYNC
 *
 * RFC 7162 is the right answer to this problem and plMail cannot use it:
 * webklex/php-imap implements neither QRESYNC nor CONDSTORE — there is no
 * ENABLE, no MODSEQ parsing, no VANISHED response handling anywhere in the
 * library — so there is no supported path to a server-computed diff. Adding one
 * means implementing a protocol extension inside a vendored client, which is a
 * great deal more code than this and fails closed on every server that does not
 * advertise it anyway.
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
     * @return array{uidValidity: int, uids: array<int,true>, flags: array<int,list<string>>, readAt: \DateTimeImmutable}|null
     *         null when the server did not answer. `uids` is a set rather than a
     *         list: the caller's only question of it is membership, once per
     *         local row. `flags` is keyed by the same UIDs, and `readAt` is when
     *         the question was put to the server — which the flag reconciler
     *         needs, because "what the server said" is only meaningful together
     *         with "and when".
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

            // Stamped before the command rather than after it. The reconciler
            // compares this against local flag changes, and the safe direction
            // to be wrong in is "this answer is older than it really is": that
            // makes a race resolve in favour of the local write, which is the
            // one the user made.
            $readAt = new \DateTimeImmutable();

            $found = $this->listWithFlags($connection);

            if (false === is_array($found)) {
                return null;
            }

            $uids  = [];
            $flags = [];

            foreach ($found as $uid => $messageFlags) {
                $uid = (int) $uid;

                $uids[$uid] = true;

                // A message with no flags at all answers with an empty list,
                // which is a fact — "this message is unread and unstarred" —
                // and not a missing answer. Anything that is not a list is the
                // missing answer, and it is left out rather than read as empty.
                if (false === is_array($messageFlags)) {
                    continue;
                }

                $flags[$uid] = array_values(array_map(
                    static fn (mixed $flag): string => (string) $flag,
                    $messageFlags,
                ));
            }

            return [
                'uidValidity' => (int) $examined['uidvalidity'],
                'uids'        => $uids,
                'flags'       => $flags,
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

    /**
     * Every UID in the selected folder with its flags, in as few commands as
     * this connection allows.
     *
     * On the socket client — what plMail actually uses — that is one command:
     * `UID FETCH 1:* (FLAGS)`, which webklex spells as a $to of INF. With a $to
     * set at all its single-message filter is skipped, so every FETCH line in
     * the response is collected and keyed by UID, and that keying is what makes
     * presence and flags a single answer from a single instant.
     *
     * fetch() is on ImapProtocol rather than on ProtocolInterface, so the
     * narrowing is real and not a formality: webklex also ships LegacyProtocol,
     * which wraps the ext-imap functions and has no such method. That path
     * falls back to what the interface does guarantee — a UID SEARCH for
     * presence, then flags() for the flags — which is the two commands and two
     * instants this class exists to avoid, and is still better than a
     * legacy-backed account never learning a flag changed.
     *
     * @return array<int|string,mixed>|false  false when the server did not
     *         answer in a shape worth reading
     */
    private function listWithFlags(ProtocolInterface $connection): array|false
    {
        if (true === $connection instanceof ImapProtocol) {
            // INF is how webklex spells the open-ended `1:*`, and it says so in
            // that method's own docblock — which then types the parameter
            // int|null, a float being the one value that actually reaches the
            // `$to == INF` branch. The library is inconsistent with itself
            // here; the call is right.
            // @phpstan-ignore argument.type
            $found = $connection->fetch(['FLAGS'], 1, INF, IMAP::ST_UID)->validatedData();

            return is_array($found) ? $found : false;
        }

        $found = $connection->search(['ALL'], IMAP::ST_UID)->validatedData();

        if (false === is_array($found)) {
            return false;
        }

        $uids = array_map(intval(...), array_values($found));

        if ([] === $uids) {
            return [];
        }

        $flags = $connection->flags($uids, IMAP::ST_UID)->validatedData();

        if (false === is_array($flags)) {
            // Presence without flags is still a usable listing: the sweep is
            // the half that deletes mail, and it loses nothing here. The flag
            // pass simply has nothing to say this cycle.
            return array_fill_keys($uids, []);
        }

        return $flags;
    }
}
