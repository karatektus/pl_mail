<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Enum\Mail\MessageFlag;
use App\Infrastructure\Messaging\Message\ApplyRemoteFlagsMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\ThreadStatusUpdater;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies one flag change the server pushed, to one message.
 *
 * THROUGH ThreadStatusUpdater, AND THAT IS THE POINT
 * ─────────────────────────────────────────────────
 * Not because it is convenient, but because that is the one place every
 * provider's inbound flags land — Gmail's labels and Graph's isRead reach it
 * too — and it is where the echo guard lives. A local change the server has
 * not confirmed yet must not be reverted by the server's own stale answer, and
 * that guard is stated once there rather than re-implemented per caller. See
 * ImapFlagReconciler, whose docblock works the race through.
 *
 * A path that applied flags directly would be a second inbound door with the
 * guard missing, which is precisely the flapping this codebase already fixed.
 *
 * SILENT WHEN THE MESSAGE IS NOT HERE
 * ───────────────────────────────────
 * A notification can arrive for mail plMail has never seen: it was delivered
 * and flagged between two syncs, or it is in a mailbox somebody has since
 * disabled. Neither is an error, and neither is worth a round trip — the
 * ordinary sync ingests it shortly and reads the flags then.
 */
#[AsMessageHandler]
final readonly class ApplyRemoteFlagsMessageHandler
{
    public function __construct(
        private MailboxRepository   $mailboxRepository,
        private MessageRepository   $messageRepository,
        private ThreadStatusUpdater $status,
        private LoggerInterface     $logger,
    ) {
    }

    public function __invoke(ApplyRemoteFlagsMessage $message): void
    {
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if (null === $mailbox || false === $mailbox->isSyncEnabled) {
            return;
        }

        $subject = $this->messageRepository->findOneInMailboxByUid($mailbox, $message->uid);

        if (null === $subject) {
            $this->logger->debug('Flag notification for a message that is not here yet', [
                'mailboxId' => $message->mailboxId,
                'uid'       => $message->uid,
            ]);

            return;
        }

        // Read from the complete list the server sent rather than from the
        // presence of a delta, because that is what it is: RFC 3501 requires
        // the whole set, so an absent \Seen means UNSEEN and not "unchanged".
        $flags = MessageFlag::canonicalList($message->flags);

        $this->status->applyRemoteFlags([
            new RemoteFlagState(
                message: $subject,
                seen:    in_array(MessageFlag::SEEN->value, $flags, true),
                flagged: in_array(MessageFlag::FLAGGED->value, $flags, true),
                flags:   $flags,
            ),
        ]);
    }
}
