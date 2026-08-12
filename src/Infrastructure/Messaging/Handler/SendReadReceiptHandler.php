<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Infrastructure\Messaging\Message\SendReadReceiptMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\ReadReceiptPolicy;
use App\Service\Mail\ReadReceiptSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends one MDN, having satisfied itself that it still should.
 *
 * The policy is re-run here rather than carried on the message, and that is
 * the load-bearing decision in this class. Between the read that queued this
 * and the worker picking it up, the user may have marked the message unread,
 * deleted it, or switched the alias to "never" — and a decision serialised at
 * queue time would send a receipt the mailbox is now configured against. The
 * queue carries an id and an intent; authority stays with the policy.
 *
 * $confirmed distinguishes the two ways this gets queued. An automatic send
 * requires the policy to still say Always; an explicit confirmation only
 * requires it to still be sendable, because the user already answered the
 * question the Ask mode asks and a mode that has since been widened to Always
 * does not invalidate their click.
 */
#[AsMessageHandler]
readonly class SendReadReceiptHandler
{
    public function __construct(
        private MessageRepository $messages,
        private ReadReceiptPolicy $policy,
        private ReadReceiptSender $sender,
    ) {
    }

    public function __invoke(SendReadReceiptMessage $msg): void
    {
        $message = $this->messages->find($msg->messageId);

        if (null === $message) {
            return;
        }

        $decision = $this->policy->decide($message);

        // Never, no notify address, or the flag already cleared by a previous
        // send: all of them mean the request is not outstanding any more.
        if (false === $decision->isSendable()) {
            return;
        }

        // Only the automatic mode may fire without a human. A decision that
        // came back as Ask — including one downgraded from Always by the
        // sender mismatch — needs the explicit confirmation route, which
        // reaches ReadReceiptSender directly rather than through this handler.
        if (ReadReceiptMode::Always !== $decision->mode) {
            return;
        }

        $this->sender->send($message, $decision);
    }
}
