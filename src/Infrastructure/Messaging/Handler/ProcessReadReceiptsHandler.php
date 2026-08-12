<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ProcessReadReceiptsMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\ReadReceiptCorrelator;
use App\Service\Mail\ReadReceiptPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads a freshly ingested batch for anything read-receipt shaped.
 *
 * Two passes over the same messages, and the order between them matters only
 * in that they are exclusive: a disposition notification is never itself a
 * request for one, so a row that correlates as a receipt is not then flagged
 * as asking for a receipt. Doing it the other way round would have plMail
 * asking for a read receipt on read receipts.
 *
 * NOTHING IS SENT HERE. Flagging a message as receipt-requested is the whole
 * of the incoming half; the MDN — if any — goes out on the read transition,
 * from ThreadStatusUpdater. Sending at ingest would confirm the message was
 * read the moment it arrived, which is false for every message and is exactly
 * the confirmation the sender is fishing for when the request is hostile.
 */
#[AsMessageHandler]
readonly class ProcessReadReceiptsHandler
{
    public function __construct(
        private MessageRepository     $messages,
        private ReadReceiptCorrelator $correlator,
        private ReadReceiptPolicy     $policy,
        private MailChangeRecorder    $changes,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProcessReadReceiptsMessage $msg): void
    {
        if ([] === $msg->messageIds) {
            return;
        }

        $touched = false;

        foreach ($this->messages->findByIds($msg->messageIds) as $message) {
            if (true === $this->handleReport($message)) {
                $touched = true;

                continue;
            }

            if (true === $this->handleRequest($message)) {
                $touched = true;
            }
        }

        if (true === $touched) {
            $this->em->flush();
        }
    }

    /**
     * An inbound MDN: stamp the message it is about, file the report away.
     *
     * Both rows are announced to JMAP clients — the original because its
     * read-at just appeared and that is what a client renders, the report
     * because it was just marked read and taken off the Inbox, which is a
     * mailbox move as far as a client is concerned. A client that heard
     * neither would show the receipt as unread inbox mail forever and the sent
     * message as never read.
     */
    private function handleReport(Message $message): bool
    {
        if (false === $this->correlator->isDispositionNotification($message)) {
            return false;
        }

        $original = $this->correlator->correlate($message);

        if (null === $original) {
            // A report we could not attribute. It stays an ordinary message —
            // see ReadReceiptCorrelator::correlate().
            return false;
        }

        $accountId = (int) $message->account->id;

        $this->changes->emailChanged($accountId, (string) $message->id, created: false, thread: $message->thread);
        $this->changes->emailChanged(
            (int) $original->account->id,
            (string) $original->id,
            created: false,
            thread: $original->thread,
        );

        return true;
    }

    /**
     * An inbound message whose sender asked for a receipt.
     *
     * The flag is set from the header, and only from the header — no policy is
     * consulted here. Whether anything is ever sent is decided at read time,
     * against the setting as it stands then, and recording "they asked" is
     * true regardless of the answer. It is also what lets the message view
     * explain itself: a mailbox on "never" still knows a receipt was requested
     * and could say so, where a flag conditioned on the setting would have
     * thrown that away at ingest.
     */
    private function handleRequest(Message $message): bool
    {
        if (null !== $message->readReceiptRequested) {
            return false;
        }

        if (null === $this->policy->notifyAddress($message)) {
            return false;
        }

        $message->readReceiptRequested = true;

        return true;
    }
}
