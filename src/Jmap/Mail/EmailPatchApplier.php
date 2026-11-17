<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Label\LabelBinding\LabelBindingRepository;
use App\Repository\Label\Label\LabelRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\ThreadPinService;
use App\Service\Mail\ThreadStatusUpdater;

/**
 * Applies a JMAP patch to an Email's keywords and mailboxIds.
 *
 * Shared by Email/set update and EmailSubmission/set onSuccessUpdateEmail,
 * which the spec defines as running the identical patch semantics.
 *
 * Every mutation goes through LabelChangePropagator, the same seam the web UI
 * uses, so a change made by a JMAP client reaches Gmail/IMAP/Graph exactly as
 * one made in the browser. Its ordering contract is observed: mutate the
 * entities, call the propagator, and let the caller flush last — detachLabel
 * in particular reads message->mailbox before it is re-pointed.
 *
 * Read state is the exception: $seen goes through ThreadStatusUpdater, which
 * flushes on its own, so that a phone marking a message read or unread keeps
 * the thread's unread counter, the read receipt and the propagation exactly
 * as the web UI does.
 */
final class EmailPatchApplier
{
    public function __construct(
        private readonly LabelRepository $labelRepository,
        private readonly LabelBindingRepository $bindingRepository,
        private readonly LabelChangePropagator $propagator,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly JmapDraftWriter $draftWriter,
        private readonly ThreadStatusUpdater $status,
        private readonly ThreadPinService $pins,
    ) {
    }

    /**
     * What a client may rewrite on a draft.
     *
     * Everything a composer owns, and nothing else — no receivedAt, no
     * messageId, no threadId. Those describe where a message came from, and a
     * draft that could rewrite them could forge one.
     */
    private const array DRAFT_PROPERTIES = [
        'subject', 'to', 'cc', 'bcc', 'textBody', 'htmlBody', 'bodyValues',
        'inReplyTo', 'references', 'attachments',
        // plMail's own: the identity the draft is written from. See
        // JmapDraftWriter::senderFor().
        'identityId',
    ];

    /**
     * Accepts both the patch form ("keywords/$seen": true) and the whole-value
     * form ("keywords": {...}), which RFC 8620 §5.3 allows interchangeably.
     *
     * @param array<string,mixed> $patch
     */
    public function apply(Account $account, Message $message, array $patch): void
    {
        $keywords = $this->currentKeywords($message);
        $mailboxIds = $this->currentMailboxIds($account, $message);
        $touchesKeywords = false;
        $touchesMailboxes = false;
        $content = [];

        foreach ($patch as $path => $value) {
            $path = (string) $path;

            if ('keywords' === $path) {
                $keywords = $this->requireMap($value, 'keywords');
                $touchesKeywords = true;
                continue;
            }

            if ('mailboxIds' === $path) {
                $mailboxIds = $this->requireMap($value, 'mailboxIds');
                $touchesMailboxes = true;
                continue;
            }

            if (1 === preg_match('#^keywords/(.+)$#', $path, $matches)) {
                $keywords = $this->patchMap($keywords, $matches[1], $value);
                $touchesKeywords = true;
                continue;
            }

            if (1 === preg_match('#^mailboxIds/(.+)$#', $path, $matches)) {
                $mailboxIds = $this->patchMap($mailboxIds, $matches[1], $value);
                $touchesMailboxes = true;
                continue;
            }

            // Content, which is editable on a draft and on nothing else. A
            // received message's body is a record of what arrived; letting a
            // client rewrite it would make the mailbox unfalsifiable.
            if (true === in_array($path, self::DRAFT_PROPERTIES, true)) {
                if (false === $message->hasFlag(MessageFlag::DRAFT)) {
                    throw new MethodException(
                        'invalidPatch',
                        sprintf('"%s" can only be changed on a draft.', $path),
                    );
                }

                $content[$path] = $value;
                continue;
            }

            throw new MethodException('invalidPatch', sprintf('Property "%s" cannot be updated.', $path));
        }

        // Resolved before anything is written. The draft update flushes and
        // the keyword step tells Gmail/IMAP/Graph, so a bad mailbox id found
        // afterwards used to answer notUpdated for a patch that was half
        // applied — locally and on the provider — leaving the client unable
        // to say what the message now is.
        $wantedMailboxes = true === $touchesMailboxes
            ? $this->resolveMailboxes($account, $mailboxIds)
            : null;

        if ([] !== $content) {
            $this->draftWriter->update($account, $message, $content);
        }

        if (true === $touchesKeywords) {
            $this->applyKeywords($message, $keywords);
        }

        if (null !== $wantedMailboxes) {
            $this->applyMailboxes($message, $wantedMailboxes);
        }
    }

    /**
     * Only $seen, $flagged and $pinned are settable: the first two are the
     * keywords with a dedicated column and a propagation path, and $pinned is
     * the conversation's pin (#654), which has no provider to tell. $draft and $answered live in
     * the IMAP flags mirror, which the sync layer owns — a client clearing
     * $draft is therefore accepted and ignored rather than rejected, since
     * that is exactly what EmailSubmission/set's onSuccessUpdateEmail sends
     * and the send path clears the flag itself.
     *
     * @param array<string,bool> $keywords
     */
    private function applyKeywords(Message $message, array $keywords): void
    {
        $wantSeen = true === ($keywords['$seen'] ?? false);
        $wantFlagged = true === ($keywords['$flagged'] ?? false);

        $isSeen = null !== $message->seenAt;
        $isFlagged = null !== $message->starredAt;

        // The pin is the conversation's, so one email's keyword pins all of
        // them — through the service the web's button uses, for the end time
        // and the change log. Past the reminder count too: an app asks its own
        // "pin anyway?" before it sends this.
        $thread = $message->thread;

        if (null !== $thread && (true === ($keywords['$pinned'] ?? false)) !== $thread->isPinned()) {
            true === ($keywords['$pinned'] ?? false)
                ? $this->pins->pin([$thread])
                : $this->pins->unpin([$thread]);
        }

        if ($wantSeen !== $isSeen) {
            // Through the same service as the web UI's read button, not by
            // writing seenAt here. Writing it here left the thread's
            // unreadCount alone, and the web list draws bold from that counter:
            // a message marked unread on the phone stayed read in the browser,
            // and one marked read there stayed bold. The service also tells
            // the provider, so the propagator is not called a second time.
            $this->status->markRead([$message], $wantSeen);
        }

        if ($wantFlagged !== $isFlagged) {
            $starredAt = true === $wantFlagged ? new \DateTimeImmutable() : null;

            $message->starredAt = $starredAt;

            $thread = $message->thread;

            if (null !== $thread) {
                $thread->starredAt = $starredAt;
            }

            $this->propagator->star([$message], $wantFlagged);
        }
    }

    /**
     * The labels a mailboxIds value names, refusing any the account does not
     * have. Changes nothing, so it can run before any part of the patch does.
     *
     * @param array<string,bool> $mailboxIds
     *
     * @return non-empty-array<int,Label>
     */
    private function resolveMailboxes(Account $account, array $mailboxIds): array
    {
        $wanted = [];

        foreach ($mailboxIds as $mailboxId => $enabled) {
            if (true !== $enabled) {
                continue;
            }

            if (false === ctype_digit((string) $mailboxId)) {
                throw new MethodException('invalidProperties', sprintf('No such Mailbox "%s".', $mailboxId));
            }

            // Mailbox ids are binding ids — resolving through the binding is
            // also what keeps a client from naming another account's mailbox.
            $bindings = $this->bindingRepository->findForAccountAndIds((int) $account->id, [(int) $mailboxId]);
            $label = ($bindings[0] ?? null)?->label;

            if (null === $label) {
                throw new MethodException('invalidProperties', sprintf('No such Mailbox "%s".', $mailboxId));
            }

            $wanted[(int) $label->id] = $label;
        }

        if (0 === count($wanted)) {
            throw new MethodException('invalidProperties', 'An Email must belong to at least one Mailbox.');
        }

        return $wanted;
    }

    /**
     * @param array<int,Label> $wanted from resolveMailboxes()
     */
    private function applyMailboxes(Message $message, array $wanted): void
    {
        $current = [];

        foreach ($message->labels as $label) {
            $current[(int) $label->id] = $label;
        }

        foreach ($wanted as $labelId => $label) {
            if (true === array_key_exists($labelId, $current)) {
                continue;
            }

            $message->addLabel($label);
            $this->propagator->attachLabel([$message], $label);
        }

        foreach ($current as $labelId => $label) {
            if (true === array_key_exists($labelId, $wanted)) {
                continue;
            }

            // Order matters: the DB mutation first, then detachLabel, which
            // inspects the (still un-re-pointed) mailbox to decide on a move.
            $message->removeLabel($label);
            $this->propagator->detachLabel([$message], $label);
        }

        // A change of mailboxes is a move made from an app, and a pin follows
        // the owner's rule for moves exactly as it does on the web (#654): the
        // bin and spam take it off, anywhere else it is "Keep the pin when
        // moved". Nothing changed, nothing moved.
        $moved = [] !== array_diff_key($current, $wanted) || [] !== array_diff_key($wanted, $current);

        if (true === $moved && null !== $message->thread) {
            $discarded = false;

            foreach ($wanted as $label) {
                if (LabelRole::Trash === $label->role || LabelRole::Spam === $label->role) {
                    $discarded = true;
                }
            }

            $this->pins->moved([$message->thread], $discarded);
        }

        $this->threadLabelSynchronizer->sync($message->thread);
    }

    /**
     * @return array<string,bool>
     */
    private function currentKeywords(Message $message): array
    {
        $keywords = [];

        if (null !== $message->seenAt) {
            $keywords['$seen'] = true;
        }

        if (null !== $message->starredAt) {
            $keywords['$flagged'] = true;
        }

        if (true === $message->thread?->isPinned()) {
            $keywords['$pinned'] = true;
        }

        return $keywords;
    }

    /**
     * The message's current mailboxIds, in the BINDING id space.
     *
     * message_label stores user-scoped label ids, but a JMAP Mailbox id is a
     * per-account binding id — which is what the incoming patch keys are and
     * what the resolution below expects. Emitting label ids here made a patch
     * removing one mailbox leave the others behind as ids from the wrong
     * space, and the resolution then rejected them as "No such Mailbox".
     *
     * Both are autoincrement ints from different tables, so the two spaces
     * overlap and the failure looks like a client sending nonsense rather than
     * like a translation that was skipped. Same reason EmailMapper translates
     * on the way out.
     *
     * @return array<string,bool>
     */
    private function currentMailboxIds(Account $account, Message $message): array
    {
        $bindingIdByLabelId = $this->bindingRepository->bindingIdsByLabelId((int) $account->id);
        $ids = [];

        foreach ($message->labels as $label) {
            $bindingId = $bindingIdByLabelId[(int) $label->id] ?? null;

            if (null === $bindingId) {
                continue;
            }

            $ids[(string) $bindingId] = true;
        }

        return $ids;
    }

    /**
     * @param array<string,bool> $map
     *
     * @return array<string,bool>
     */
    private function patchMap(array $map, string $key, mixed $value): array
    {
        if (null === $value || false === $value) {
            unset($map[$key]);

            return $map;
        }

        if (true !== $value) {
            throw new MethodException('invalidPatch', 'A patch value must be true, false or null.');
        }

        $map[$key] = true;

        return $map;
    }

    /**
     * @return array<string,bool>
     */
    private function requireMap(mixed $value, string $property): array
    {
        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be an object.', $property));
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (true === $entry) {
                $map[(string) $key] = true;
            }
        }

        return $map;
    }
}
