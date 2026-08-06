<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Mail\EmailPatchApplier;
use App\Jmap\Mail\IdentityResolver;
use App\Jmap\Mail\QueuedSubmission;
use App\Jmap\Mail\SubmissionEnvelope;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * "EmailSubmission/set" (RFC 8621 §7.5) — sending.
 *
 * Sending is delegated to the existing SendMessageMessage / SendMessageHandler
 * / MessageSendService pipeline, the same one the web composer's send button
 * uses. That service already performs the draft->sent transition (adds Sent,
 * removes Drafts, clears the \Draft flag, sets sentAt, re-points the mailbox),
 * so a client that omits onSuccessUpdateEmail still ends up correct.
 *
 * A submission has no table of its own: its id IS the Email id. That is enough
 * to satisfy the object model here because plMail sends each draft at most
 * once (MessageSendService is a no-op once sentAt is set), so the mapping
 * stays one-to-one and EmailSubmission/get can reconstruct from the Message.
 *
 * **What "reconstruct" needs is written to the row here**, in two columns that
 * exist for no other reader: `submissionSendAt` (the release time, set on every
 * accepted submission) and `submissionCancelledAt`. Before them the release
 * time lived only in this method's response and in a messenger envelope, so a
 * held submission was invisible to get for exactly the hours a client wanted to
 * show it — see Message's docblocks for both.
 *
 * undoStatus is reported as "pending": the send is queued on the messenger bus
 * and has genuinely not happened yet when this returns. Note the web
 * composer's fixed ten-second undo window is deliberately NOT applied — a JMAP
 * client asked to send now, and a client that wants a delay says so.
 *
 * Three things the client decides, and where each is read:
 *
 * - **Which address it leaves as** — `identityId`, resolved through
 *   IdentityResolver against the account's sendable aliases. Unresolvable ids
 *   are refused (forbiddenFrom) rather than quietly sent as the account's own
 *   address, which is what this method did before it read the property at all.
 * - **When it leaves** — FUTURERELEASE parameters on the envelope, parsed by
 *   SubmissionEnvelope and honoured as a DelayStamp on the bus.
 * - **Whether it still leaves** — an update of `undoStatus` to "canceled",
 *   which sets the same `Message::$cancelled` flag the web composer's undo
 *   button sets and SendMessageHandler reads when the envelope comes due, plus
 *   the durable `submissionCancelledAt` that lets get keep saying so.
 *   Genuinely best-effort, and see applyUpdates() for what it cannot promise.
 */
final class EmailSubmissionSetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly IdentityResolver $identityResolver,
        private readonly EmailPatchApplier $patchApplier,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function name(): string
    {
        return 'EmailSubmission/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

        $oldState = $this->stateManager->stateFor($accountId, JmapObjectType::EmailSubmission);

        // One clock for the whole call: two creates in one request that both
        // asked for HOLDFOR 3600 must come back with the same sendAt.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];

        /** @var array<string,QueuedSubmission> $queued creationId => what to hand the bus after the flush */
        $queued = [];

        $create = $arguments['create'] ?? null;

        if (null !== $create) {
            if (false === is_array($create)) {
                throw new MethodException('invalidArguments', '"create" must be an object.');
            }

            foreach ($create as $creationId => $properties) {
                $creationId = (string) $creationId;

                if (false === is_array($properties)) {
                    $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];
                    continue;
                }

                try {
                    $submission = $this->submit($account, $properties, $context, $now);
                } catch (MethodException $exception) {
                    $notCreated[$creationId] = $exception->toError();
                    continue;
                }

                $id = (string) $submission->message->id;

                $this->stateManager->recordCreated($accountId, JmapObjectType::EmailSubmission, $id);
                $context->recordCreatedId($creationId, $id);
                $queued[$creationId] = $submission;

                $created[$creationId] = [
                    'id' => $id,
                    'sendAt' => $submission->sendAt->format('Y-m-d\TH:i:s\Z'),
                    'undoStatus' => 'pending',
                ];
            }
        }

        $this->applyUpdates($account, $arguments['update'] ?? null, $context, $now, $updated, $notUpdated);

        $updatedEmails = $this->applyOnSuccess($account, $arguments, $queued);

        $this->entityManager->flush();

        // After the flush, not before. A submission can rewrite the From
        // address the mail goes out with (identityId), and the worker reads
        // that off the row rather than out of the envelope — dispatching first
        // races the commit, and the mail that loses the race leaves as the
        // address the client did not pick. Nothing else moved: the envelope
        // still carries only the id.
        foreach ($queued as $submission) {
            $this->bus->dispatch(
                new SendMessageMessage((int) $submission->message->id),
                $submission->delayMs > 0 ? [new DelayStamp($submission->delayMs)] : [],
            );
        }

        $result = [
            'accountId' => (string) $accountId,
            'oldState' => $oldState,
            'newState' => $this->stateManager->stateFor($accountId, JmapObjectType::EmailSubmission),
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => [],
            'notDestroyed' => new \stdClass(),
        ];

        if (count($updatedEmails) > 0) {
            // The spec has the server report the implicit Email/set it just
            // performed, so the client does not have to re-fetch.
            $result['updatedEmails'] = $updatedEmails;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $properties
     */
    private function submit(Account $account, array $properties, JmapContext $context, \DateTimeImmutable $now): QueuedSubmission
    {
        $emailId = $properties['emailId'] ?? null;

        if (false === is_string($emailId) || '' === $emailId) {
            throw new MethodException('invalidProperties', 'An "emailId" is required.');
        }

        $resolved = $context->resolveId($emailId);

        if (null === $resolved) {
            throw new MethodException('invalidProperties', sprintf('Unknown creation id "%s".', $emailId));
        }

        $message = $this->findOne($account, $resolved);

        if (null === $message) {
            throw new MethodException('invalidProperties', 'No such Email in this account.');
        }

        if (null !== $message->sentAt) {
            throw new MethodException('alreadyExists', 'That Email has already been submitted.');
        }

        $recipients = $this->recipients($message);

        if (0 === count($recipients)) {
            throw new MethodException('noRecipients', 'The Email has no recipients.');
        }

        $mailFrom = $this->applyIdentity($account, $message, $properties['identityId'] ?? null);

        $envelope = SubmissionEnvelope::parse($properties['envelope'] ?? null, $mailFrom, $recipients, $now);

        // Clears a cancel that was never spent. `cancelled` is consumed by the
        // first envelope that comes due, so a hold cancelled and then
        // submitted again would otherwise have the *new* send swallowed by the
        // old flag and the mail would never go out, with "pending" returned
        // for it.
        $message->cancelled = false;

        $sendAt = $envelope->sendAt($now);

        // The release time goes on the row, not only into the response. It is
        // what EmailSubmission/get reconstructs `sendAt` and `undoStatus:
        // "pending"` from while the mail is held, and — being set for every
        // accepted submission rather than only for held ones — it is also the
        // marker that says this Email HAS a submission. Without that, "queued
        // and not gone yet" and "a draft nobody submitted" are the same row,
        // and get has to answer notFound for both.
        $message->submissionSendAt = $sendAt;

        // Re-submitting is a new submission, so the old cancel stops being the
        // answer to "what is this submission doing?" — otherwise get would
        // report canceled for mail that is on its way.
        $message->submissionCancelledAt = null;

        return new QueuedSubmission($message, $sendAt, $envelope->delayMs($now));
    }

    /**
     * The From this submission sends as, applied to the Message the worker
     * will read.
     *
     * A client that names no identity gets what the draft already carries,
     * which keeps every existing caller — and every draft written by the web
     * composer, which already resolved an alias of its own — exactly as it
     * was.
     *
     * Only fromAddress is written. fromName stays the account's name because
     * MessageSendService builds the From display name from the account and
     * never reads the column, so setting it here would change what the local
     * copy shows and nothing that goes out — a difference between the two that
     * is worse than the alias display name being unused.
     */
    private function applyIdentity(Account $account, Message $message, mixed $identityId): string
    {
        if (null === $identityId) {
            return (string) ($message->fromAddress ?? $account->displayAddress ?? $account->email ?? '');
        }

        if (false === is_string($identityId) || '' === $identityId) {
            throw new MethodException('invalidProperties', '"identityId" must be a non-empty string.');
        }

        $address = $this->identityResolver->addressFor($account, $identityId);

        if (null === $address) {
            // forbiddenFrom is the spec's answer for "not an address you may
            // send as" (RFC 8621 §7.5), and the id is echoed because a client
            // holding a stale Identity/get list needs to know which entry to
            // drop.
            throw new MethodException('forbiddenFrom', sprintf(
                'Identity "%s" is not a sendable address of this account; use one from Identity/get.',
                $identityId,
            ));
        }

        $message->fromAddress = $address;

        return $address;
    }

    /**
     * The only update RFC 8621 §7.5 allows on a submission: undoStatus to
     * "canceled" while it is still pending.
     *
     * Honoured through Message::$cancelled, which SendMessageHandler reads and
     * clears when the envelope comes due — the same mechanism, to the line, as
     * the web composer's undo button. That is what makes this honest to offer
     * rather than a recall the queue cannot perform: nothing is pulled out of
     * the queue, the envelope still arrives, and the handler declines to send.
     *
     * What it therefore cannot do, and what a client must not read into it:
     *
     * - It cannot unsend. Once sentAt is set the mail has left, and the update
     *   is refused with cannotUnsend rather than accepted as a no-op.
     * - There is a window. An immediate submission is dispatched with no delay
     *   and a worker may already be inside MessageSendService, in which case
     *   the flag is set on a message that is being sent. The cancel is
     *   reliable only for a held submission, which is the case it exists for.
     *
     * It IS reported afterwards, which it did not used to be:
     * `submissionCancelledAt` is written beside the flag, and
     * EmailSubmission/get answers `undoStatus: "canceled"` from it rather than
     * notFound. The two columns are needed because `cancelled` alone is a
     * one-shot flag — SendMessageHandler clears it when the envelope comes due
     * — so it stops being evidence of anything minutes after the cancel.
     *
     * **A draft that was never submitted cannot be cancelled**, and that is a
     * refusal this method did not make before. It used to set the flag on any
     * Email of the account, which had two consequences: the client was told
     * "updated" for a submission that get answers notFound for, and the flag
     * sat there until some envelope consumed it — so the user's next send from
     * the web composer was silently swallowed by a cancel aimed at something
     * that never existed.
     *
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(
        Account $account,
        mixed $update,
        JmapContext $context,
        \DateTimeImmutable $now,
        array &$updated,
        array &$notUpdated,
    ): void {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;
            $resolved = $context->resolveId($id);
            $message = null === $resolved ? null : $this->findOne($account, $resolved);

            if (null === $message) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such EmailSubmission in this account.'];
                continue;
            }

            if (false === is_array($patch) || ['undoStatus'] !== array_keys($patch)) {
                $notUpdated[$id] = [
                    'type' => 'invalidProperties',
                    'properties' => ['undoStatus'],
                    'description' => 'Only "undoStatus" may be updated, and only to "canceled".',
                ];
                continue;
            }

            if ('canceled' !== $patch['undoStatus']) {
                $notUpdated[$id] = [
                    'type' => 'invalidProperties',
                    'properties' => ['undoStatus'],
                    'description' => 'undoStatus may only be set to "canceled".',
                ];
                continue;
            }

            if (null !== $message->sentAt) {
                $notUpdated[$id] = ['type' => 'cannotUnsend', 'description' => 'That Email has already been sent.'];
                continue;
            }

            // After the cannotUnsend check, deliberately: mail that has left is
            // better described by "you cannot unsend that" than by "there is no
            // such submission", and a message sent by the web composer is
            // gettable as a final submission, so notFound would contradict get.
            if (null === $message->submissionSendAt) {
                $notUpdated[$id] = [
                    'type' => 'notFound',
                    'description' => 'That Email has no submission to cancel; submit it first.',
                ];
                continue;
            }

            $message->cancelled = true;
            $message->submissionCancelledAt = $now;
            $updated[$id] = null;

            // Recorded now, where it deliberately was not before. The reason it
            // was not is gone: the submission used to be ungettable while
            // pending, so a change entry would have woken every client to
            // re-fetch an id answering notFound. It now answers "canceled",
            // which is a different object from the "pending" the client is
            // holding — and EmailSubmission/changes has to say so, or a second
            // device goes on showing mail as scheduled that will never leave.
            $this->stateManager->recordUpdated($account->id, JmapObjectType::EmailSubmission, $id);
        }
    }

    /**
     * onSuccessUpdateEmail patches are keyed by "#creationId" of the
     * submission they are conditional on, and only run for submissions that
     * actually succeeded.
     *
     * @param array<string,mixed>            $arguments
     * @param array<string,QueuedSubmission> $queued
     *
     * @return array<string,mixed>
     */
    private function applyOnSuccess(Account $account, array $arguments, array $queued): array
    {
        $onSuccess = $arguments['onSuccessUpdateEmail'] ?? null;

        if (false === is_array($onSuccess) || 0 === count($queued)) {
            return [];
        }

        $updatedEmails = [];

        foreach ($onSuccess as $reference => $patch) {
            $creationId = ltrim((string) $reference, '#');
            $submission = $queued[$creationId] ?? null;

            if (null === $submission || false === is_array($patch)) {
                continue;
            }

            $message = $submission->message;

            $this->patchApplier->apply($account, $message, $patch);
            $this->stateManager->recordUpdated($account->id, JmapObjectType::Email, (string) $message->id);

            $updatedEmails[(string) $message->id] = null;
        }

        return $updatedEmails;
    }

    /**
     * Every address the mail will actually be sent to, which is what an
     * envelope's rcptTo is compared against.
     *
     * @return list<string>
     */
    private function recipients(Message $message): array
    {
        $recipients = [];

        foreach ([$message->toAddresses, $message->ccAddresses, $message->bccAddresses] as $group) {
            if (false === is_array($group)) {
                continue;
            }

            foreach ($group as $entry) {
                $address = is_array($entry) ? ($entry['address'] ?? null) : null;

                if (true === is_string($address) && '' !== $address) {
                    $recipients[] = $address;
                }
            }
        }

        return $recipients;
    }

    private function findOne(Account $account, string $id): ?Message
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        $messages = $this->messageRepository->findByAccountAndIds($account->id, [(int) $id]);

        return $messages[0] ?? null;
    }
}
