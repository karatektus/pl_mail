<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ApplyGmailLabelsMessage;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Graph\GraphLabelPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Translates a semantic mail operation into provider-appropriate async
 * propagation jobs. Callers mutate the DB first (source of truth), then
 * call propagate() with the affected messages.
 *
 * Provider mapping:
 *   Gmail — every operation is a label mutation via messages.batchModify.
 *   IMAP  — star/read map to flags; archive/trash/delete map to moves as
 *           before; custom label attach is DB-only (the physical folder is
 *           untouched while the location label stays); custom label detach
 *           triggers a physical move ONLY when the detached label was the
 *           message's location label (rule: the message must live somewhere).
 *
 * Location resolution when the location label is detached, in order:
 *   1. a remaining system Trash/Spam label with a backing folder
 *   2. a remaining folder-backed custom label (last attached wins)
 *   3. no folder-backed label remains → this is an archive → 'archive' action
 *
 * IMPORTANT: for IMAP moves, callers must pass messages BEFORE flush() so
 * message->mailbox still reflects the source folder; this service captures the
 * messageId => sourceMailboxId map and optimistically re-points
 * message->mailbox to the destination for 'move'.
 */
final readonly class LabelChangePropagator
{
    public function __construct(
        private MessageBusInterface $bus,
        private MailboxRepository   $mailboxRepository,
        private LabelRepository     $labelRepository,
        private GraphLabelPolicy    $labelPolicy,
        private LoggerInterface     $logger,
    ) {}

    /**
     * @param iterable<Message> $messages
     */
    public function star(iterable $messages, bool $starred): void
    {
        $imapAction = 'unflag';

        if (true === $starred) {
            $imapAction = 'flag';
        }

        $messages = $this->markFlagsInFlight($messages);

        $this->dispatchFlags($messages, $imapAction);
        $this->dispatchGmail($messages, $this->gmailStarPayload($starred));
        $this->dispatchGraph($messages);
    }

    /**
     * @param iterable<Message> $messages
     */
    public function markRead(iterable $messages, bool $read): void
    {
        $imapAction = 'unseen';

        if (true === $read) {
            $imapAction = 'seen';
        }

        $messages = $this->markFlagsInFlight($messages);

        $this->dispatchFlags($messages, $imapAction);
        $this->dispatchGmail($messages, $this->gmailReadPayload($read));
        $this->dispatchGraph($messages);
    }

    /**
     * Archive = remove Inbox. IMAP messages physically move to the Archive
     * folder (handler resolves the destination as before).
     *
     * @param iterable<Message> $messages
     */
    public function archive(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'archive');
        $this->dispatchGmail($messages, ['add' => [], 'remove' => ['INBOX']]);
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Archive));
    }

    /**
     * @param iterable<Message> $messages
     */
    public function trash(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'trash');
        $this->dispatchGmail($messages, ['add' => ['TRASH'], 'remove' => ['INBOX']]);
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Trash));
    }

    /**
     * @param iterable<Message> $messages
     */
    /**
     * Out of the bin (or out of spam) and back into the inbox.
     *
     * The counterpart trash() never had. Every provider expresses it as the
     * inverse of what put the mail there: Gmail attaches INBOX and drops both
     * TRASH and SPAM, IMAP moves the message back into the account's inbox
     * folder, Graph moves it into the inbox.
     *
     * Both Gmail labels are removed rather than only the one it was found
     * under, because a spam mail somebody then deleted carries both — and one
     * that came back to the inbox still marked SPAM would be filed away again
     * by Gmail without plMail doing anything.
     *
     * @param iterable<Message> $messages
     */
    public function restore(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'restore');
        $this->dispatchGmail($messages, ['add' => ['INBOX'], 'remove' => ['TRASH', 'SPAM']]);
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Inbox));
    }

    /**
     * Discarding a draft — get rid of this copy wherever it exists.
     *
     * Named delete() and reached only from ComposeController::discard(). It is
     * NOT plMail's permanent delete: that is MessagePurger, which cannot use
     * this and says why. The difference is that a discard leaves the local row
     * to be removed by its own caller, so a handler here can still look the
     * message up — a purge removes it first, and a handler that looked it up
     * would find nothing and leave the mail on the server forever.
     *
     * Gmail gets TRASH rather than a real delete, and for a discarded draft
     * that is right rather than a compromise: the draft was never sent, Gmail's
     * bin is where its own client puts a discarded one, and it is recoverable
     * for the user who did not mean it.
     *
     * @param iterable<Message> $messages
     */
    public function delete(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'delete');
        $this->dispatchGmail($messages, ['add' => ['TRASH'], 'remove' => []]);
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Trash));
    }

    /**
     * Attach a custom label. IMAP: DB-only — the message keeps its physical
     * location as long as the location label stays attached.
     *
     * @param iterable<Message> $messages
     */
    public function attachLabel(iterable $messages, Label $label): void
    {
        $this->dispatchGmail($messages, ['add' => [(string) $label->id], 'remove' => []]);

        // Graph: a folder-backed label is a move into that folder; a plain
        // (category) label is derived from DB state by the handler. Folder
        // backing is per-account (it lives on the binding), so the decision
        // is made once per account group rather than once per call.
        $this->dispatchGraph($messages, function (Account $account) use ($label): ?int {
            if (true === $this->labelPolicy->pushesAsFolder($label, $account)) {
                return $label->id;
            }

            return null;
        });
    }

    /**
     * Detach a custom label. IMAP: physical move only when the detached
     * label was the message's location label — resolved per message.
     *
     * MUST be called after the DB label mutation but BEFORE re-pointing or
     * flushing message->mailbox; this method handles the re-point itself.
     *
     * @param iterable<Message> $messages
     */
    public function detachLabel(iterable $messages, Label $label): void
    {
        $this->dispatchGmail($messages, ['add' => [], 'remove' => [(string) $label->id]]);
        $this->dispatchGraph($messages);

        // ── IMAP location handling ────────────────────────────────────────
        /** @var array<string, array<int,int>> $moves  destinationPath → messageId => sourceMailboxId */
        $moves = [];
        /** @var array<string, array<int,int>> $moveUids  destinationPath → messageId => source UID */
        $moveUids = [];
        /** @var array<int, Message[]> $archives  accountless bucket for rule 3 */
        $archives = [];

        foreach ($messages as $message) {
            $sourceMailbox = $message->mailbox;

            if (null === $sourceMailbox || null === $message->imapUid) {
                continue;
            }

            if ($sourceMailbox->label !== $label) {
                // Not the location label — DB-only, message stays put.
                continue;
            }

            $destinationMailbox = $this->resolveDestinationMailbox($message);

            if (null === $destinationMailbox) {
                // Rule 3: nothing folder-backed remains — archive.
                $archives[] = $message;
                continue;
            }

            $moves[$destinationMailbox->fullPath][$message->id]    = $sourceMailbox->id;
            $moveUids[$destinationMailbox->fullPath][$message->id] = $message->imapUid;

            // Optimistic re-point. The source address goes with it: the UID
            // this row held belongs to the folder it is leaving, and the
            // destination will issue its own. See Message::relocateTo().
            $message->relocateTo($destinationMailbox);
        }

        foreach ($moves as $destinationPath => $idMap) {
            $this->bus->dispatch(new ApplyImapFlagsMessage(
                $idMap,
                'move',
                $destinationPath,
                $moveUids[$destinationPath] ?? [],
            ));
        }

        if (count($archives) > 0) {
            $this->dispatchFlags($archives, 'archive');
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Rule 2 resolution: system Trash/Spam first, then folder-backed custom
     * labels (last attached wins).
     */
    private function resolveDestinationMailbox(Message $message): ?\App\Entity\Mail\Mailbox
    {
        $account         = $message->mailbox->account;
        $systemCandidate = null;
        $customCandidate = null;

        foreach ($message->labels as $remaining) {
            if (true === $remaining->isSystem) {
                $role = $remaining->role;

                if (null !== $role && true === in_array($role->value, ['trash', 'spam'], true)) {
                    $systemCandidate = $remaining;
                }

                continue;
            }

            $customCandidate = $remaining; // last one wins
        }

        $candidates = [];

        if (null !== $systemCandidate) {
            $candidates[] = $systemCandidate;
        }

        if (null !== $customCandidate) {
            $candidates[] = $customCandidate;
        }

        foreach ($candidates as $candidate) {
            // The binding for this account is the folder link — no query.
            $mailbox = $candidate->bindingFor($account)?->mailbox;

            if (null !== $mailbox) {
                return $mailbox;
            }
        }

        return null;
    }

    /**
     * Write down that these messages carry a flag change the provider has not
     * confirmed yet, and hand back a list the three dispatchers can each walk.
     *
     * This is the write half of the echo-race guard, and it belongs here rather
     * than in the callers because this is the one place an outbound *flag* op
     * is queued — for every provider at once. ThreadStatusUpdater and the JMAP
     * EmailPatchApplier both arrive through star()/markRead(), so neither has
     * to remember to mark anything, and neither can forget.
     *
     * Only star() and markRead() call it. A move is not a flag change: the
     * inbound flag pass reads \Seen and \Flagged, and an archive that is still
     * in flight is a question about where the row lives, which the deletion
     * sweep and SentCopyReconciler already answer between them.
     *
     * No flush. Every caller mutates the row and flushes afterwards — that
     * ordering is the whole contract of this class — so this rides along on the
     * flush the mutation was already going to do.
     *
     * @param iterable<Message> $messages
     *
     * @return list<Message>
     */
    private function markFlagsInFlight(iterable $messages): array
    {
        $now  = new \DateTimeImmutable();
        $list = [];

        foreach ($messages as $message) {
            $message->flagsTouchedAt = $now;

            $list[] = $message;
        }

        return $list;
    }

    /**
     * @param iterable<Message> $messages
     */
    private function dispatchFlags(iterable $messages, string $action): void
    {
        $idMap  = [];
        $uidMap = [];

        foreach ($messages as $message) {
            if (null === $message->imapUid || null === $message->mailbox) {
                continue;
            }

            $idMap[$message->id]  = $message->mailbox->id;
            // The address travels with the job. The caller is about to
            // relocate this row, which clears the UID, so this is the last
            // moment it can be read at all.
            $uidMap[$message->id] = $message->imapUid;
        }

        if (count($idMap) === 0) {
            return;
        }

        $this->bus->dispatch(new ApplyImapFlagsMessage($idMap, $action, null, $uidMap));
    }

    /**
     * @param iterable<Message>                              $messages
     * @param array{add: list<string>, remove: list<string>} $payload
     */
    private function dispatchGmail(iterable $messages, array $payload): void
    {
        if (count($payload['add']) === 0 && count($payload['remove']) === 0) {
            return;
        }

        /** @var array<int, int[]> $byAccount accountId → messageIds */
        $byAccount = [];

        foreach ($messages as $message) {
            $gmailId = $message->gmailId;

            if (null === $gmailId || '' === $gmailId) {
                continue;
            }

            $account = $this->gmailAccountFor($message);

            if (null === $account) {
                continue;
            }

            $byAccount[(int) $account->id][] = (int) $message->id;
        }

        foreach ($byAccount as $accountId => $messageIds) {
            $this->bus->dispatch(new ApplyGmailLabelsMessage(
                $accountId,
                $messageIds,
                $payload['add'],
                $payload['remove'],
            ));
        }
    }
    /**
     * @param iterable<Message>        $messages
     * @param (callable(Account): ?int)|null $moveToLabelResolver  per-account
     *        destination-label resolver, or null for a state-only push
     */
    private function dispatchGraph(iterable $messages, ?callable $moveToLabelResolver = null): void
    {
        /** @var array<int, int[]> $byAccount */
        $byAccount = [];
        /** @var array<int, Account> $accounts */
        $accounts = [];

        foreach ($messages as $message) {
            $graphId = $message->graphId;

            if (null === $graphId || '' === $graphId) {
                continue;
            }

            $account = $this->accountOf($message);

            if (false === $account->isMicrosoft()) {
                continue;
            }

            $accountId               = (int) $account->id;
            $byAccount[$accountId][] = (int) $message->id;
            $accounts[$accountId]    = $account;
        }

        foreach ($byAccount as $accountId => $messageIds) {
            $moveToLabel = null !== $moveToLabelResolver
                ? $moveToLabelResolver($accounts[$accountId])
                : null;

            $this->bus->dispatch(new ApplyGraphChangesMessage($accountId, $messageIds, $moveToLabel));
        }
    }

    /**
     * The account that can tell Gmail about this message, or null if none can.
     *
     * Normally the message's own — a native Gmail row belongs to the account
     * that fetched it. The exception is Gmailify, and it is not a rare one for
     * anybody using it: Google fetches another mailbox into Gmail, both are
     * connected here, and SyncGmailMessageBatchHandler merges the two copies
     * onto the IMAP row rather than keeping both. That row then carries a
     * gmailId while belonging to an account with no Gmail credentials.
     *
     * This asked `accountOf($message)->isGmail()` and therefore answered "no
     * Gmail here" for every one of those, silently. The message had a usable
     * Gmail id and a Gmail account sitting beside it, and archive, trash,
     * restore, star, mark-read and every label change stopped at IMAP. The
     * symptom is a mail dragged out of spam that Hetzner obeys and Gmail files
     * straight back, because Gmail was never told.
     *
     * The carrier is stored rather than looked up. A gmailId is issued by one
     * Gmail mailbox and means nothing to another, so on an install with two
     * Google accounts there is no deriving which one — see
     * Message::$gmailCarrierAccount.
     *
     * A carrier that is somehow no longer a Gmail account is refused rather
     * than used. The column is set null when the account is disconnected, but
     * an account can also be re-authenticated as something else, and pushing a
     * Gmail id at whatever it is now is worse than not pushing it.
     */
    private function gmailAccountFor(Message $message): ?Account
    {
        $own = $this->accountOf($message);

        if (true === $own->isGmail()) {
            return $own;
        }

        $carrier = $message->gmailCarrierAccount;

        return null !== $carrier && true === $carrier->isGmail() ? $carrier : null;
    }

    private function graphDestinationLabelId(Account $account, LabelRole $role): ?int
    {
        $label = $this->labelRepository->findOneByRoleForUser($role, $account->usr);

        if (null === $label) {
            $this->logger->warning('LabelChangePropagator: no destination label for Graph move', [
                'accountId' => $account->id,
                'role'      => $role->value,
            ]);

            return null;
        }

        return $label->id;
    }
    /**
     * Message::$account is non-null, so this is direct now. It used to fall
     * back to an attached label's account, which stopped being meaningful
     * once labels became user-scoped — and was never needed, since every
     * message has carried its own account all along.
     */
    private function accountOf(Message $message): Account
    {
        return $message->account;
    }

    /**
     * @return array{add: list<string>, remove: list<string>}
     */
    private function gmailStarPayload(bool $starred): array
    {
        if (true === $starred) {
            return ['add' => ['STARRED'], 'remove' => []];
        }

        return ['add' => [], 'remove' => ['STARRED']];
    }

    /**
     * @return array{add: list<string>, remove: list<string>}
     */
    private function gmailReadPayload(bool $read): array
    {
        if (true === $read) {
            return ['add' => [], 'remove' => ['UNREAD']];
        }

        return ['add' => ['UNREAD'], 'remove' => []];
    }
}
