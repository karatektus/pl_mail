<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Mail\IdentityResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;

/**
 * "EmailSubmission/get" (RFC 8621 §7.2).
 *
 * Reconstructed from the Message rather than stored: a submission id is the
 * Email id, and three columns on that row say which of the spec's states it is
 * in — `submissionSendAt` that it exists at all and when it is due,
 * `submissionCancelledAt` that it was called off, `sentAt` that it has gone.
 *
 * | On the row | undoStatus | sendAt |
 * |---|---|---|
 * | sentAt set | `final` | when it left |
 * | submissionCancelledAt set | `canceled` | when it would have left |
 * | submissionSendAt only | `pending` | when it is due |
 * | none of them | notFound | — |
 *
 * **Held submissions used to be notFound, and that was the bug this method
 * exists in its current shape to fix.** Anything without a sentAt was skipped,
 * so a scheduled send answered "no such submission" for the entire hold, then
 * appeared as `final` — meaning the release time a client showed its user was
 * only ever the one string in the create response. Lose that response and the
 * schedule was unknowable, which pushed every client into keeping its own
 * device-local copy of a decision the server had already made.
 *
 * A cancelled submission is reported rather than disappearing, for the same
 * reason: "it was called off" and "there is no such thing" are different facts
 * and a client can only act on the first.
 *
 * An id that names a draft nobody ever submitted is still notFound. That is not
 * a state of a submission — it is the absence of one.
 */
final class EmailSubmissionGetMethod implements JmapMethod
{
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly IdentityResolver $identityResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'EmailSubmission/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

        $requestedIds = $arguments['ids'] ?? null;

        if (null === $requestedIds) {
            throw new MethodException('requestTooLarge', '"ids" is required for EmailSubmission/get.');
        }

        if (false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($requestedIds) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per EmailSubmission/get.', self::MAX_OBJECTS));
        }

        $requestedIds = array_values(array_map(
            static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
            $requestedIds,
        ));

        $messages = $this->messageRepository->findByAccountAndIds(
            $accountId,
            array_map('intval', $requestedIds),
        );

        $list = [];
        $found = [];

        foreach ($messages as $message) {
            // Sent mail is a submission whatever produced it, including the web
            // composer — the transition is the same one, and a client that sees
            // a sent Email is entitled to the submission that describes it.
            // Anything else needs a submission of its own to have been accepted.
            if (null === $message->sentAt && null === $message->submissionSendAt) {
                continue;
            }

            $found[] = (string) $message->id;
            $list[] = $this->toJmap($account, $message);
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::EmailSubmission),
            'list' => $list,
            'notFound' => array_values(array_diff($requestedIds, $found)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toJmap(Account $account, Message $message): array
    {
        $id = (string) $message->id;

        return [
            'id' => $id,
            // The identity the mail actually left as, matched on the From
            // address. This used to be the accountId, which is not an id
            // Identity/get publishes at all once the account has aliases — so
            // a client that followed it looked up an identity that was not
            // there.
            'identityId' => $this->identityResolver->identityIdFor($account, $message->fromAddress),
            'emailId' => $id,
            'threadId' => null === $message->thread ? null : (string) $message->thread->id,
            'envelope' => [
                'mailFrom' => ['email' => (string) $message->fromAddress, 'parameters' => null],
                'rcptTo' => $this->recipients($message),
            ],
            // The instant the mail left where it has, and the instant it is due
            // where it has not. Falling back to submissionSendAt rather than
            // reporting the schedule for a sent submission: what a client wants
            // once mail is gone is when it went, and those differ by however
            // long the worker took.
            'sendAt' => $this->utc($message->sentAt ?? $message->submissionSendAt),
            'undoStatus' => $this->undoStatus($message),
            'deliveryStatus' => null,
            'dsnBlobIds' => [],
            'mdnBlobIds' => [],
        ];
    }

    /**
     * RFC 8621 §7's three values, read off the row in the order they settle:
     * sent is final whatever else happened to it, a cancel outranks the hold it
     * called off, and everything remaining is still on its way.
     */
    private function undoStatus(Message $message): string
    {
        if (null !== $message->sentAt) {
            return 'final';
        }

        if (null !== $message->submissionCancelledAt) {
            return 'canceled';
        }

        return 'pending';
    }

    /**
     * The spec's date-time format, in UTC.
     *
     * Null-tolerant although the loop above has already established that one of
     * the two instants exists: the guarantee lives in a guard clause several
     * lines away, and a reader of this method should not have to find it to
     * know that `sendAt: null` cannot be produced here by accident.
     */
    private function utc(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return list<array{email:string,parameters:null}>
     */
    private function recipients(Message $message): array
    {
        $recipients = [];

        foreach ([$message->toAddresses, $message->ccAddresses, $message->bccAddresses] as $group) {
            if (false === is_array($group)) {
                continue;
            }

            foreach ($group as $entry) {
                if (false === is_array($entry)) {
                    continue;
                }

                $address = $entry['address'] ?? null;

                if (true === is_string($address) && '' !== $address) {
                    $recipients[] = ['email' => $address, 'parameters' => null];
                }
            }
        }

        return $recipients;
    }
}
