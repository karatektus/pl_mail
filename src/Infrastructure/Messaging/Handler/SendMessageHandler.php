<?php

namespace App\Infrastructure\Messaging\Handler;
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
 */
#[AsMessageHandler]
readonly class SendMessageHandler
{
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

        try {
            $sent = $this->sendService->send($message);
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
            $this->releaseAfterFailure($msg->messageId);

            // SAID OUT LOUD. A refused send used to end here, silently: the
            // senders turn every failure into `return false`, so Messenger
            // considers the message handled — no retry, no failed-transport
            // row, nothing — and the browser had already been told the mail
            // was sent two seconds before the attempt was even made.
            $this->outcomes->failed($message);

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
}
