<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Flags the server changed under us, and which of them plMail is allowed to
 * believe.
 *
 * ## The gap this closes
 *
 * plMail never re-read the flags of a message it had already stored. They were
 * captured once, at ingest, in MessageSyncer::buildMessage() — and then never
 * again, because incremental sync asks for `lastSeenUid+1:*` and no UID below
 * the high-water mark is ever looked at a second time. Mail read on a phone
 * stayed unread here forever. A star set in Roundcube never arrived. Not
 * eventually: never.
 *
 * The listing that VanishedMessageReconciler already runs every quarter of an
 * hour now carries flags as well as presence — see ImapFolderListing for why
 * that is cheaper than keeping the two apart — so this needs no round trip of
 * its own. It is handed what that one command said and decides what it means.
 *
 * ## The echo race, and the guard
 *
 * This is the hard part, and it is a race the outbound direction never had.
 *
 * The user marks a message read. The local row changes first, because the
 * database is the source of truth, and an ApplyImapFlagsMessage is queued to
 * carry it to the server. That job may be queued, in flight, or waiting behind
 * a NAS that is asleep. For all of that time the server's honest answer to
 * "what are this message's flags" is the *old* one — and a pass that believed
 * it would clear seenAt, which the propagator would notice as a local change
 * and queue an outbound job for, which the next pass would revert again. Two
 * directions, each correctly reporting what it sees, flapping forever.
 *
 * Note what does *not* fix this: comparing the local change's timestamp against
 * the moment the server was asked. The local write happens *before* the read —
 * that is the whole shape of the race — so it is older, and a last-write-wins
 * on those two timestamps chooses the server every time.
 *
 * What the local change actually is, is *unconfirmed*. So that is what is
 * written down: Message::$flagsTouchedAt is set by LabelChangePropagator where
 * the outbound job is queued, and cleared by ApplyImapFlagsHandler where the
 * server accepts it.
 *
 * The guard itself is not applied here. It lives in
 * ThreadStatusUpdater::applyRemoteFlags(), which is the one place every
 * provider's inbound flags land — Gmail's labels and Graph's isRead reach it
 * too — because one rule enforced once is a rule no future caller can forget to
 * apply. What this class contributes is the read time, since the guard is
 * measured from the moment the server was asked.
 *
 * ## Three answers again
 *
 * A folder that could not be listed produces no flag pass, exactly as it
 * produces no sweep: the caller never gets here. Within a listing, a UID the
 * server did not describe is skipped rather than read as "no flags" — an
 * absent answer is not the answer "unread and unstarred", and treating it as
 * one would mark a folder's worth of read mail unread. The same discipline
 * ImapUidPresence is built on.
 */
final readonly class ImapFlagReconciler
{
    public function __construct(
        private MessageRepository   $messages,
        private ThreadStatusUpdater $status,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Apply one folder listing's flags to the rows that folder holds.
     *
     * @param array<int,list<string>> $flagsByUid  as the server spelled them
     * @param DateTimeImmutable       $readAt      when the server was asked
     *
     * @return int how many rows changed
     */
    public function reconcile(Mailbox $mailbox, array $flagsByUid, DateTimeImmutable $readAt): int
    {
        if ([] === $flagsByUid) {
            return 0;
        }

        $located = $this->messages->findLocatedByUid($mailbox);

        if ([] === $located) {
            return 0;
        }

        $states = [];

        foreach ($located as $uid => $message) {
            $flags = $flagsByUid[$uid] ?? null;

            if (null === $flags) {
                // The server did not describe this UID. The sweep is what draws
                // conclusions from absence, with the safety rails; here it is
                // simply not an answer, and an unanswered UID is left exactly
                // as it is.
                continue;
            }

            $states[] = new RemoteFlagState(
                $message,
                $this->hasFlag($flags, 'seen'),
                $this->hasFlag($flags, 'flagged'),
                MessageFlag::canonicalList($flags),
            );
        }

        // The read time goes with the states rather than being taken there,
        // because the echo guard is measured from the moment the server was
        // asked. ThreadStatusUpdater owns that judgement for every provider —
        // see its applyRemoteFlags() docblock.
        $changed = $this->status->applyRemoteFlags($states, $readAt);

        if ($changed > 0) {
            $this->logger->info('Refreshed message flags from the server', [
                'mailbox'  => $mailbox->fullPath,
                'changed'  => $changed,
                'compared' => count($states),
            ]);
        }

        return $changed;
    }

    /**
     * Whether a flag list contains one flag, whatever the server called it.
     *
     * The backslash is part of the IMAP wire syntax for a system flag rather
     * than part of its name, and servers are inconsistent about whether it
     * survives into a parsed response — webklex hands back `Seen` from some and
     * `\Seen` from others. Comparing on the bare lower-cased name is the only
     * comparison that is right on both.
     *
     * @param list<string> $flags
     */
    private function hasFlag(array $flags, string $wanted): bool
    {
        foreach ($flags as $flag) {
            if ($wanted === strtolower(ltrim($flag, '\\'))) {
                return true;
            }
        }

        return false;
    }

}
