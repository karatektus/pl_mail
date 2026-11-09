<?php

namespace App\Service\Imap;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

final class MessageThreader
{
    /**
     * Reply/forward prefixes, in the languages this app is translated into plus
     * the ones common European clients emit. Used both to strip the prefix for
     * subject normalisation and to decide whether a message is a reply at all.
     */
    private const string REPLY_PREFIX_PATTERN = '/^\s*((re|fwd|fw|aw|wg|antw|sv|vs|res|rif|tr|doorst)\s*(\[\d+\])?\s*:\s*)+/i';

    /**
     * How far back the subject fallback may reach for a parent thread. Long
     * enough to cover a slow-moving conversation, short enough that a recurring
     * subject starts a fresh thread instead of extending a stale one.
     */
    private const string SUBJECT_FALLBACK_WINDOW = '-30 days';

    /** How many provider conversation ids to remember before starting over. */
    private const int PROVIDER_THREAD_CACHE_LIMIT = 500;

    /**
     * Threads already handed out for a provider conversation id, keyed by
     * "accountId|providerThreadKey". See providerThread().
     *
     * @var array<string, MessageThread>
     */
    private array $providerThreads = [];

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $messageThreadRepository,
        private readonly ThreadMerger            $threadMerger,
        private readonly ThreadLabelSynchronizer $threadLabels,
    )
    {
    }

    /**
     * Assigns a newly-synced message to a thread, creating one if needed.
     * Must be called after the message itself has its message_id, in_reply_to,
     * thread_references, subject, and addresses populated, but the entity
     * does not need to be persisted yet — this method will associate it
     * with a thread and the caller is responsible for the final flush.
     *
     * $recordArrival false leaves the arrival half — the sort key, the
     * category, the new-mail marker — to a later recordArrival() call. The
     * ingest pipeline asks for that so the user's rules have filed the message
     * before it is allowed to move its conversation. See recordArrival().
     */
    public function assignThread(Message $message, Account $account, bool $recordArrival = true): void
    {
        // 1. The provider's own conversation id, when there is one. Gmail and
        //    Graph have already grouped the conversation the way the user sees
        //    it in their web client; nothing we derive can beat that — and
        //    nothing below it runs, the reverse lookup included: the provider
        //    has already put the replies where they belong.
        $providerThreadKey = $message->providerThreadKey;

        if (null !== $providerThreadKey && '' !== $providerThreadKey) {
            $this->attachMessageToThread(
                $message,
                $this->providerThread($providerThreadKey, $account, $message),
                $recordArrival,
            );

            return;
        }

        $this->assignThreadByHeaders($message, $account, $recordArrival);

        // 4. The other direction: mail that already answers this message.
        $this->adoptReplies($message, $account);
    }

    /**
     * Steps 2 and 3 of assignThread(): what the message's own headers, and
     * failing them its subject, say it belongs to.
     */
    private function assignThreadByHeaders(Message $message, Account $account, bool $recordArrival): void
    {
        // 2. RFC 5322 References / In-Reply-To: the thread of a message it names.
        $referenceIds = $this->collectReferenceIds($message);

        if (count($referenceIds) > 0) {
            $parentMessage = $this->messageRepository->findOneByMessageIdsForAccount($referenceIds, $account);

            if ($parentMessage !== null && $parentMessage->thread !== null) {
                $thread = $parentMessage->thread;
                $this->attachMessageToThread($message, $thread, $recordArrival);

                return;
            }

            // 2b. Nothing it names is here, but other mail may name the same
            //     conversation start. See joinSiblings().
            if (true === $this->joinSiblings($message, $account, $recordArrival)) {
                return;
            }

            // Headers are valid even though no parent has been synced yet —
            // method is about what the message provides, not whether a match occurred.
            $thread = $this->createThread($message, $account, ThreadingMethod::References);
            $this->attachMessageToThread($message, $thread, $recordArrival);

            return;
        }

        // 3. No usable references at all. Subject matching is the last resort and
        //    is deliberately narrow: it exists to rescue replies from clients that
        //    omit References, not to group unrelated mail that happens to share a
        //    subject line. A message that is not itself a reply always starts its
        //    own thread — otherwise every "Your order has shipped" notification
        //    ever received collapses into one endless conversation.
        if (true === $this->hasReplyPrefix($message->subject)) {
            $normalizedSubject = $this->normalizeSubject($message->subject);

            if ($normalizedSubject !== '') {
                $candidateThread = $this->messageThreadRepository->findMatchingNormalizedSubjectThreadForAccount(
                    $normalizedSubject,
                    $account,
                    new \DateTimeImmutable(self::SUBJECT_FALLBACK_WINDOW),
                );

                if ($candidateThread !== null && $this->participantsOverlap($message, $candidateThread)) {
                    $this->attachMessageToThread($message, $candidateThread, $recordArrival);

                    return;
                }
            }
        }

        $thread = $this->createThread($message, $account, ThreadingMethod::SubjectFallback);
        $this->attachMessageToThread($message, $thread, $recordArrival);
    }

    /**
     * Bring mail that answers this message into its thread.
     *
     * Steps 2 and 3 only ever look backwards, from a reply to what it answers,
     * so the result depended on the order mail was synced in. Replies synced
     * before the message they answer — INBOX before Sent Items is the ordinary
     * case, folders being synced one after the other — found nothing, started
     * threads of their own, and nothing looked back when the parent arrived:
     * one conversation of four messages ended up in three threads (#172). Each
     * of those threads is folded into this message's thread now, so the order
     * no longer matters, and a chain arriving in reverse closes up link by link
     * as each parent lands.
     *
     * One pass over the direct and indirect replies, with no recursion, so a
     * reference cycle or a message naming itself cannot loop: whatever is
     * already in this thread is skipped, and that includes the message itself.
     * A reply not threaded yet — later in the same batch — is skipped as well,
     * since step 2 will find this message from its side.
     */
    private function adoptReplies(Message $message, Account $account): void
    {
        $rawMessageId = $message->messageId;

        if (null === $rawMessageId) {
            return;
        }

        $messageId = MessageIdHelper::normalise($rawMessageId);

        if ('' === $messageId) {
            return;
        }

        foreach ($this->messageRepository->findRepliesToForAccount($messageId, $account) as $reply) {
            $thread      = $message->thread;
            $replyThread = $reply->thread;

            if (null === $thread || null === $replyThread || $replyThread === $thread) {
                continue;
            }

            // The lookup is scoped by the message's account, and a thread by
            // its own. They agree; this is the guard that keeps them from ever
            // merging two people's mail if somewhere they did not.
            if ($replyThread->account !== $thread->account) {
                continue;
            }

            $message->thread = $this->threadMerger->merge($thread, $replyThread);
        }
    }

    /**
     * Join the conversation of other mail that starts from the same message,
     * when that message itself was never received (#377).
     *
     * GitHub mails every comment on an issue with References naming the post
     * that opened it, and does not mail that post to the person who wrote it.
     * Step 2 looks for a parent by Message-ID, finds none, and every comment
     * used to open a thread of its own; adoptReplies() cannot help either,
     * because the siblings name the missing message, never each other. The
     * same happens to any conversation whose first message the account never
     * got: a mailing list joined halfway, or a first message deleted on the
     * server before it was synced.
     *
     * Other clients group these, and so does the JWZ algorithm everybody's
     * threading descends from: a missing parent becomes an empty placeholder,
     * and what hangs off it is one conversation.
     *
     * ONLY THE ROOT
     * ─────────────
     * The id asked about is the first entry of References, the conversation's
     * start, and not every id the message names. A later id two messages
     * share means they share that id's ancestors too, the root among them, so
     * nothing is lost by it; what it avoids is two replies being merged on an
     * intermediate id alone, when a client that rewrites References put one
     * there that the rest of the conversation does not carry. Mail without
     * References falls back to In-Reply-To, which then is all there is.
     *
     * Every other conversation found from that root is folded into the one
     * joined. That repairs a split left by earlier syncs as soon as one more
     * message of it arrives, and it is the same merge adoptReplies() makes.
     *
     * Message rows are flushed before they are threaded, so the lookup sees
     * siblings from the same batch too; one not threaded yet has no thread in
     * memory either and is skipped, because it will find this one in turn.
     *
     * @return bool whether the message joined an existing thread
     */
    private function joinSiblings(Message $message, Account $account, bool $recordArrival): bool
    {
        $rootId = $this->rootReferenceId($message);

        if (null === $rootId) {
            return false;
        }

        $joined = null;

        foreach ($this->messageRepository->findRepliesToForAccount($rootId, $account) as $sibling) {
            $siblingThread = $sibling->thread;

            if ($sibling === $message || null === $siblingThread || $siblingThread === $joined) {
                continue;
            }

            // Same guard as adoptReplies(): never two people's mail in one thread.
            if ($siblingThread->account !== $account) {
                continue;
            }

            if (null === $joined) {
                $this->attachMessageToThread($message, $siblingThread, $recordArrival);
                $joined = $siblingThread;

                continue;
            }

            $joined          = $this->threadMerger->merge($joined, $siblingThread);
            $message->thread = $joined;
        }

        return null !== $joined;
    }

    /**
     * The Message-ID the conversation started from, as far as this message
     * says: the first entry of References, else the first of In-Reply-To.
     */
    private function rootReferenceId(Message $message): ?string
    {
        $references = MessageIdHelper::normaliseList($message->references);

        if (count($references) > 0) {
            return $references[0];
        }

        return MessageIdHelper::normaliseList($message->inReplyTo)[0] ?? null;
    }

    /**
     * The thread for a provider conversation id, created on first sight.
     *
     * The repository only sees what has been flushed, and a sync batch threads
     * every message it built before flushing once at the end. Two messages of
     * the same Gmail/Graph conversation in one batch therefore both missed the
     * lookup, each made a thread, and the second INSERT hit
     * uniq_message_thread_provider_key_account — taking the whole batch with
     * it. Threads handed out here are remembered for exactly as long as the
     * unit of work still holds them.
     */
    private function providerThread(string $providerThreadKey, Account $account, Message $message): MessageThread
    {
        $cacheKey = $account->id . '|' . $providerThreadKey;
        $pending  = $this->providerThreads[$cacheKey] ?? null;

        // A worker handles many messages, and the entity manager is cleared
        // between them — anything it no longer manages is a stale reference.
        if (null !== $pending && true === $this->entityManager->contains($pending)) {
            return $pending;
        }

        $thread = $this->messageThreadRepository->findOneByProviderThreadKeyForAccount($providerThreadKey, $account);

        if (null === $thread) {
            $thread = $this->createThread($message, $account, ThreadingMethod::Provider);
            $thread->providerThreadKey = $providerThreadKey;
        }

        // Long-running workers never stop adding keys, so the cache has to be
        // bounded — but only entries the repository lookup above can already
        // serve are safe to drop. A thread without an id is an unflushed
        // INSERT: evicting it mid-batch made the next message of the same
        // conversation create it a second time, and the batch-end flush died
        // on uniq_message_thread_provider_key_account.
        if (count($this->providerThreads) >= self::PROVIDER_THREAD_CACHE_LIMIT) {
            $this->providerThreads = array_filter(
                $this->providerThreads,
                static fn (MessageThread $cached): bool => null === $cached->id,
            );
        }

        $this->providerThreads[$cacheKey] = $thread;

        return $thread;
    }

    /**
     * @return string[] message-ids this message references, most specific first
     */
    private function collectReferenceIds(Message $message): array
    {
        // Normalised again on read, not just on write: rows synced before the
        // write paths were fixed still hold bracketed ids, and those have to keep
        // matching until the rethread backfill has run.
        return MessageIdHelper::normaliseList(array_merge(
            MessageIdHelper::normaliseList($message->inReplyTo),
            MessageIdHelper::normaliseList($message->references),
        ));
    }

    /**
     * Is this message a reply or forward, as far as its subject line admits?
     *
     * This is the gate on subject-based threading. Automated notifications never
     * carry a reply prefix, so they can never be merged into an existing thread.
     */
    public function hasReplyPrefix(?string $subject): bool
    {
        if (null === $subject) {
            return false;
        }

        return 1 === preg_match(self::REPLY_PREFIX_PATTERN, $subject);
    }

    public function normalizeSubject(?string $subject): string
    {
        if ($subject === null) {
            return '';
        }

        $normalized = trim($subject);

        // Strip repeated Re:/Fwd:/AW:/… prefixes, including spaced and counted
        // variants like "RE :", "Fwd:Fwd:" and "Re[2]:".
        $normalized = preg_replace(self::REPLY_PREFIX_PATTERN, '', $normalized);

        if ($normalized === null) {
            $normalized = trim($subject);
        }

        return mb_strtolower(trim($normalized));
    }
    /**
     * Keep a single-message draft thread's subject in step with the draft.
     * No-op once the thread holds anything else — a reply draft must never
     * rename the conversation it hangs off.
     */
    public function resyncDraftThreadSubject(Message $message): void
    {
        $thread = $message->thread;

        if (null === $thread) {
            return;
        }

        if ($thread->messageCount > 1) {
            return;
        }

        $thread->subject = $message->subject;
        $thread->normalizedSubject = $this->normalizeSubject($message->subject);
    }

    /**
     * Does this message share a participant with the thread?
     *
     * Sender *and* recipients are considered: a reply from someone who has not
     * posted before shares no sender with the thread, but addresses someone who
     * has. Comparing senders alone would reject exactly the replies the subject
     * fallback exists to catch.
     */
    private function participantsOverlap(Message $message, MessageThread $thread): bool
    {
        $addresses = [];

        $fromAddress = $message->fromAddress;

        if (null !== $fromAddress && '' !== $fromAddress) {
            $addresses[] = $fromAddress;
        }

        foreach ([$message->toAddresses, $message->ccAddresses] as $recipients) {
            foreach ($recipients ?? [] as $recipient) {
                $address = is_array($recipient) ? ($recipient['address'] ?? null) : $recipient;

                if (is_string($address) && '' !== $address) {
                    $addresses[] = $address;
                }
            }
        }

        if (0 === count($addresses)) {
            return false;
        }

        return $this->messageRepository->existsWithAnyFromAddressInThread($addresses, $thread);
    }

    private function createThread(Message $message, Account $account, ThreadingMethod $threadingMethod): MessageThread
    {
        $thread = new MessageThread();
        $thread->account = $account;
        $thread->subject = $message->subject;
        $thread->normalizedSubject = $this->normalizeSubject($message->subject);
        $thread->threadingMethod = $threadingMethod;
        $thread->messageCount = 0;
        $thread->unreadCount = 0;
        // Seed from the message that opens the thread; attachMessageToThread()
        // takes over from here. Primary only when the message is uncategorised,
        // which is the case for locally-composed drafts.
        //
        // NO CATEGORY AT ALL for a message being held for the assistant. Null
        // is how a thread says it is in no tab — every tab query filters on an
        // exact category — and that is the whole of what holding means; see
        // Message::$categoryHeldAt. ClassifyMailHandler fills it in on release.
        $thread->category = true === $message->isCategoryHeld()
            ? null
            : $message->category ?? MessageCategory::Primary;
        // A held message's thread is in no tab at all, and that includes the
        // reader's own: the filter that would file it has not run yet either.
        $thread->userCategory = true === $message->isCategoryHeld()
            ? null
            : $message->ruleCategory;
        $thread->attachmentCount = 0;

        // The new-mail marker starts null — nobody has been shown this row yet
        // — EXCEPT when the thread is opened by a draft, which is mail the user
        // is writing at this moment. Announcing "New" over your own half-typed
        // reply the next time you open Drafts is the marker answering a
        // question about arrival for something that never arrived. Sent mail
        // needs no arm of its own: a new conversation you send starts life as
        // the draft caught here, and a reply joins a thread that already exists.
        $thread->listedAt = true === $message->isDraft() ? new \DateTimeImmutable() : null;

        $this->entityManager->persist($thread);

        return $thread;
    }
    private function attachMessageToThread(Message $message, MessageThread $thread, bool $recordArrival): void
    {
        $message->thread = $thread;

        $thread->messageCount = $thread->messageCount + 1;

        foreach ($message->labels as $label) {
            $thread->addLabel($label);
        }

        // Counted on only while nothing in the conversation is in Spam or the
        // bin, which is nearly always. Otherwise which messages count depends
        // on all of them (MessageThread::unreadAmong()), and the synchronizer
        // recounts from the conversation as a whole — loading it, which is
        // why that is not the way for every reply.
        if (true === $thread->holdsSetAsideMail()) {
            $this->threadLabels->sync($thread);
        } elseif (null === $message->seenAt) {
            $thread->unreadCount = $thread->unreadCount + 1;
        }

        if (true === $message->hasAttachments) {
            $thread->attachmentCount = $thread->attachmentCount + 1;
        }

        if (true === $recordArrival) {
            $this->recordArrival($message);
        }
    }

    /**
     * What a message arriving in its conversation does to where that
     * conversation is shown: the sort key and the category move to it, and
     * unread mail makes the conversation new again.
     *
     * Not for mail that arrives set aside — in Spam or in the bin — into a
     * conversation that has mail outside them. A reply a filter files as spam
     * brought its conversation back to the top of the Inbox, unread-new and in
     * the spam's tab, although the only thing that had happened to it was
     * spam (#470). A conversation made of set-aside mail only still moves, so
     * that Spam and Trash keep their own order.
     *
     * Its own step so that the ingest pipeline can run it after the user's
     * rules: a filter is what decides that a message is spam, and it decides
     * after threading, because its actions reach the thread. assignThread()
     * still does both at once for everybody else.
     */
    public function recordArrival(Message $message): void
    {
        $thread = $message->thread;

        if (null === $thread) {
            return;
        }

        if (true === $message->isSetAside()
            && true === $this->messageRepository->existsNotSetAsideInThread($thread, $message)) {
            return;
        }

        if (null === $message->seenAt) {
            // Unread mail joining a conversation makes it new AGAIN.
            //
            // listedAt was only ever written when a thread was created, so a
            // reply landing on a conversation somebody had already looked at
            // arrived permanently un-new: the marker had been retired by the
            // render that showed the conversation before the reply existed.
            // The one case the feature is most obviously for — somebody
            // answering you — was the one case it could not announce.
            //
            // Guarded on unread rather than on direction. A sent copy coming
            // back from the server is claimed by SentCopyReconciler onto the
            // row plMail already had, which was marked seen when it was sent,
            // so it never reaches this branch and cannot announce your own mail
            // back at you. A draft cannot either — attachMessageToThread is not
            // how drafts join a thread, and createThreadFor stamps listedAt for
            // exactly that reason.
            $thread->listedAt = null;
        }

        $this->recordActivity($message, $thread);
    }

    /**
     * Move the thread's sort key forward to this message, if this message is
     * newer than anything the thread has seen.
     *
     * Every thread list in the app orders on lastMessageAt, so this is what
     * decides where a conversation sits. Two callers, deliberately: the ingest
     * path via recordArrival() above, and MessageSendService the moment
     * a reply leaves. The send half is the newer one — before it, only incoming
     * mail could move a thread, so answering a conversation left it exactly
     * where it had been, which is not what any mail client has done for fifteen
     * years.
     *
     * Two rules, and each is a stated edge case:
     *
     * Monotonic. Only ever forward, never back. A message that arrives late but
     * is dated last week — a backfilled folder, a delayed relay, a sender with
     * a wrong clock — must not drag a live conversation down the list.
     *
     * Drafts may seed, never advance. An unsent draft is not activity: writing
     * half a reply and abandoning it must not reorder the inbox, and neither
     * must an autosave every few seconds. But a draft that *opens* its own
     * thread is the only thing that thread has, and leaving lastMessageAt null
     * there would sort it by nothing at all (Postgres puts NULLs first on a
     * DESC order, so it would pin itself to the top of every list it appears
     * in). So a draft may set the key on a thread that has none, and is ignored
     * on a thread that already has one.
     */
    public function recordActivity(Message $message, MessageThread $thread): void
    {
        $occurredAt           = $this->occurredAt($message);
        $currentLastMessageAt = $thread->lastMessageAt;

        if (null === $currentLastMessageAt) {
            $thread->lastMessageAt = $occurredAt;
            $this->adoptCategory($message, $thread);

            return;
        }

        if (true === $this->isUnsentDraft($message)) {
            return;
        }

        $advanced = $occurredAt > $currentLastMessageAt;

        if (true === $advanced) {
            $thread->lastMessageAt = $occurredAt;
        }

        // Received mail is weighed against what placed the conversation, not
        // against lastMessageAt: a reply you sent last is not what a received
        // message has to be newer than. adoptCategory() decides.
        if (true === $advanced || false === $message->isFiledAsSent()) {
            $this->adoptCategory($message, $thread);
        }
    }

    /**
     * Re-adopt a message's category onto its thread after that message has been
     * recategorised in place — the Gmail re-read path, where new CATEGORY_*
     * labels land on a row the thread has held for months.
     *
     * recordActivity() cannot do this job: a message being re-read is already
     * counted in the thread. Most-recent-wins is adoptCategory()'s own test —
     * adopt when this message is at least as new as the received message that
     * placed the conversation. Ties adopt, matching the backfill's DISTINCT ON —
     * it picks between two rows of equal date without ceremony either.
     */
    public function refreshCategory(Message $message, MessageThread $thread): void
    {
        $this->adoptCategory($message, $thread);
    }

    /**
     * The instant a message counts as having happened, for ordering purposes.
     *
     * createdAt closes the chain: every Message sets it in its constructor and
     * the column is NOT NULL, so there is always an instant to order by even
     * for a draft that has neither been received nor sent.
     */
    private function occurredAt(Message $message): \DateTimeImmutable
    {
        return $message->receivedAt
            ?? $message->sentAt
            ?? $message->createdAt;
    }

    /**
     * sentAt as well as the keyword: MessageSendService clears $draft and sets
     * sentAt in the same breath, but a caller holding a stale entity — or a row
     * that lost the keyword some other way — must still not count as a draft
     * while it has never been sent.
     */
    private function isUnsentDraft(Message $message): bool
    {
        if (null !== $message->sentAt) {
            return false;
        }

        return $message->hasFlag(MessageFlag::DRAFT);
    }

    /**
     * Most-recent-wins, the same rule the category backfill applies in SQL.
     * Without this a thread would keep whatever category it was created with
     * and the inbox tabs — which filter on the thread, not the message — would
     * only ever move after a backfill run.
     *
     * A category somebody CHOSE is the exception, and it has to be an exception
     * here rather than only in the interface. Dragging a conversation onto
     * another tab writes categoryPinnedAt, and this line is what that flag
     * exists to stop: the next message in the thread would otherwise carry the
     * cascade's opinion back in and undo the move, days later, with no visible
     * cause. See MessageThread::$categoryPinnedAt.
     */
    private function adoptCategory(Message $message, MessageThread $thread): void
    {
        if (null !== $thread->categoryPinnedAt) {
            return;
        }

        // A held message has an answer that is about to be replaced, and
        // adopting it would move the conversation twice — which is the thing
        // holding exists to stop. The thread stays where it is until the
        // release re-resolves it.
        if (true === $message->isCategoryHeld()) {
            return;
        }

        $category = $message->category;

        if (null === $category) {
            return;
        }

        // Your own mail never moves a conversation that something received has
        // placed — answering a recruiter must not take the conversation out of
        // the tab the recruiter's mail was filed in (#405). It only places one
        // nothing else has: a conversation you started, until the answer.
        if (true === $message->isFiledAsSent()) {
            if (null !== $thread->categoryDecidedAt) {
                return;
            }
        } else {
            $occurredAt = $this->occurredAt($message);

            // Older than what placed it: a late copy, a backfilled folder.
            if (null !== $thread->categoryDecidedAt && $occurredAt < $thread->categoryDecidedAt) {
                return;
            }

            $thread->categoryDecidedAt = $occurredAt;
        }

        $thread->category = $category;

        // The reader's own tab follows the same rule and moves in the same
        // breath, including BACK to none: a newer message no filter claimed
        // takes the conversation out of "Shipping" exactly as a newer
        // Promotions message takes it out of Updates. Only a message that
        // has a category counts — an uncategorised draft is not an opinion
        // about where the conversation belongs. See
        // MessageThread::$userCategory.
        $thread->userCategory = $message->ruleCategory;
    }
}
