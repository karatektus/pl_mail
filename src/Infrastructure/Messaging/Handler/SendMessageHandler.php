<?php

namespace App\Infrastructure\Messaging\Handler;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageSendService;
use App\Service\Mail\SendOutcomeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The last thing that stands between a queued envelope and a mail leaving.
 *
 * The guard here is a CLAIM, not a read. It used to be a read — load the
 * message, look at `cancelled`, send if it was false — and that shape was the
 * "undo said cancelled and the mail went anyway" bug: the cancel's UPDATE
 * committed after this handler had read the flag and before the SMTP
 * conversation finished, so nothing in the system had lost anything except the
 * user's instruction. Reproduced by clicking cancel at 9.9s of a ten-second
 * hold: HTTP 200, "Senden abgebrochen", and the message on the wire.
 *
 * Now the read and the decision are one statement — see
 * MessageRepository::claimForSend(). A cancel that arrives first wins and this
 * handler refuses; a claim that lands first wins and the cancel is told it was
 * too late, which is the half the composer needs in order to stop lying.
 *
 * ## A send that fails is retried when that can help (#125)
 *
 * The senders used to turn every failure into `false`, and a one-minute Gmail
 * cooldown lost the send as surely as a refused recipient did. They now say
 * which kind it was (see SendFailureException), and this decides:
 *
 *   refused   said at once: the draft is marked, the toast says why;
 *   deferred  tried again, at the provider's Retry-After or a short pace of
 *             our own, for at most RETRY_BUDGET_SECONDS from the first
 *             failure — a person is waiting, and a send that cannot go in two
 *             minutes is better reported than quietly late;
 *   unknown   the mail may be out. Where the provider can be asked, the next
 *             attempt looks in Sent before sending; where it cannot (SMTP),
 *             it is reported at once as possibly sent. Never resent blind.
 *
 * Nothing is said while a retry is pending. What the retries end in is said
 * once: here for the cases decided here, and by SendGaveUpSubscriber when the
 * transport's own ladder runs out first.
 */
#[AsMessageHandler]
readonly class SendMessageHandler
{
    /** How long a send may spend being retried, from its first passing failure. */
    public const int RETRY_BUDGET_SECONDS = 120;

    /**
     * How long after a lost answer Sent is looked in. Long enough for Gmail
     * and Outlook to have filed the copy of a mail they did take.
     */
    public const int CHECK_SENT_AFTER_SECONDS = 10;

    public function __construct(
        private MessageRepository  $messageRepository,
        private MessageSendService $sendService,
        private EntityManagerInterface $em,
        private SendOutcomeNotifier $outcomes,
    ) {}

    public function __invoke(SendMessageMessage $msg): void
    {
        // Atomic: this both asks whether the send is still legitimate and takes
        // the message away from anything that would cancel it. Ordering matters
        // — nothing may be read into the identity map before this, or the send
        // below would run off a snapshot taken before the claim.
        $pinsSendAt = $msg->pinsSendAt();
        $sendAt     = true === $pinsSendAt ? $msg->sendAt : null;

        if (false === $this->messageRepository->claimForSend($msg->messageId, $pinsSendAt, $sendAt)) {
            // Cancelled, already sent, gone, held by another worker, or
            // rescheduled since this envelope was queued. All five mean the
            // same thing here, and none of them is an error.
            //
            // The `cancelled` flag is lowered again on the way past, as it
            // always was: it is one-shot traffic between the undo button and
            // this handler, and a flag left standing would swallow the next
            // genuine send of the same draft. The durable record of a cancel
            // is submission_cancelled_at, which this deliberately never touches.
            $message = $this->messageRepository->find($msg->messageId);

            // A stale envelope must not spend a cancel aimed at the schedule
            // that replaced it: EmailSubmission/set cancels by raising the flag
            // and KEEPING submission_send_at, so an older envelope coming due
            // first would lower it and the current one would then send mail
            // the user had called off. The web cancel clears the column
            // instead, and its flag is still spent by whichever envelope comes
            // by, as before.
            $current = $message?->submissionSendAt;

            if (true === $pinsSendAt && null !== $current
                && $current->format('Y-m-d H:i:s') !== $sendAt?->format('Y-m-d H:i:s')) {
                return;
            }

            if (null !== $message && true === $message->cancelled) {
                $message->cancelled = false;
                $this->em->flush();
            }

            return;
        }

        /** @var Message|null $message */
        $message = $this->messageRepository->find($msg->messageId);

        if (null === $message) {
            return;
        }

        // Read back what the claim just wrote, so the entity in memory agrees
        // with the row. Without it the flush at the end of a successful send
        // would write a stale null over our own claim.
        $this->em->refresh($message);

        // Both read before the attempt, which may clear the entity manager:
        // whether an earlier attempt lost its answer, and whether this
        // account's provider can be asked where the mail went.
        $wasUnconfirmed = null !== $message->sendUnconfirmedAt;
        $checkable      = $this->sendService->canCheckSent($message);

        try {
            $sent = $this->sendService->send($message);
        } catch (SendRefusedException $refused) {
            $this->failFinally($msg->messageId, $refused->failure());

            return;
        } catch (SendOutcomeUnknownException $unknown) {
            // Lost once, on a provider that can be asked: look in Sent on the
            // next attempt. Lost again after looking, or on SMTP, or the look
            // itself failing — the mail may be out and nobody can say.
            if (true === $checkable && false === $wasUnconfirmed) {
                $this->releaseAfterFailure($msg->messageId);
                $this->messageRepository->markSendUnconfirmed($msg->messageId);

                $this->retryWithinBudget($msg->messageId, new SendDeferredException(
                    $unknown->getMessage(),
                    SendFailure::MayHaveSent,
                    self::CHECK_SENT_AFTER_SECONDS,
                    $unknown,
                ));

                return;
            }

            $this->failFinally($msg->messageId, SendFailure::MayHaveSent);

            return;
        } catch (SendDeferredException $deferred) {
            $this->releaseAfterFailure($msg->messageId);
            $this->retryWithinBudget($msg->messageId, $deferred);

            return;
        } catch (Throwable $failure) {
            // Put it down before the exception goes on to the retry logic,
            // or every retry would be refused by our own claim.
            $this->releaseAfterFailure($msg->messageId);

            // Not told yet: this one IS retried, and announcing a failure that
            // the next attempt may undo would be the same overconfidence in the
            // other direction. Messenger exhausting its ladder is what makes it
            // final, and the toast is not the surface for that.
            throw $failure;
        }

        if (false === $sent) {
            // A message with no account to send from — the only `false` left.
            $this->failFinally($msg->messageId, SendFailure::Failed);

            return;
        }

        // And the confirmation now happens HERE, where success is actually
        // known, rather than at the eight-second mark where it was merely
        // assumed. See SendOutcomeNotifier.
        $this->outcomes->sent($message);
    }

    /**
     * A send that did not happen leaves no claim behind.
     *
     * The entity manager is cleared first because MessageSendService may have
     * left half-applied changes on the message (a Sent label added before the
     * append failed, say), and flushing those on the way out of a FAILED send
     * would record a transition that did not occur. The release is raw SQL for
     * the same reason it is raw in the repository: it must not depend on
     * whatever state the identity map is in after a failure.
     */
    private function releaseAfterFailure(int $messageId): void
    {
        $this->em->clear();
        $this->messageRepository->releaseSendClaim($messageId);
    }

    /**
     * Hand the failure back to Messenger to be tried again, or, when the wait
     * would take the send past its two minutes, end it here.
     *
     * Every retry is given its wait explicitly: the provider's Retry-After
     * when it named one, otherwise a pace that starts quick and settles at
     * half a minute — five seconds, then ten, twenty, thirty — so a blip
     * clears fast and an outage is asked a handful of times, not dozens. The
     * transport's max_retries still applies; SendGaveUpSubscriber says what
     * happens when it is reached first.
     *
     * @throws SendDeferredException to retry; returns when the send has
     *                               finally failed
     */
    private function retryWithinBudget(int $messageId, SendDeferredException $deferred): void
    {
        $elapsed = $this->messageRepository->secondsRetryingSend($messageId);
        $left    = self::RETRY_BUDGET_SECONDS - $elapsed;
        $wait    = $deferred->retryAfterSeconds() ?? min($left, max(5, min(30, $elapsed + 5)));

        if ($left <= 0 || $wait > $left) {
            $this->failFinally($messageId, $deferred->failure());

            return;
        }

        throw new SendDeferredException($deferred->getMessage(), $deferred->failure(), $wait, $deferred->getPrevious() ?? $deferred);
    }

    /**
     * The end of a send that did not go through: claim down, draft marked,
     * and the reader told why — once, at the end, never while retrying.
     */
    private function failFinally(int $messageId, SendFailure $failure): void
    {
        $this->releaseAfterFailure($messageId);
        $this->messageRepository->markSendFailed($messageId, $failure);

        $message = $this->messageRepository->find($messageId);

        if (null !== $message) {
            $this->outcomes->failed($message);
        }
    }
}
