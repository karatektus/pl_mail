<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Mercure publishing only. Contact harvesting is dispatched once per
 * account by the sync handlers (SyncAccountMessageHandler /
 * SyncImapMailboxMessageHandler / SyncGmailMessageBatchHandler harvests
 * inline), not per mailbox here.
 */
final readonly class SyncNotifier
{
    public function __construct(
        private HubInterface   $hub,
        private LoggerInterface $logger,
    )
    {
    }

    public function publishMailboxSynced(Account $account, Mailbox $mailbox): void
    {
        $this->publish(
            [
                sprintf('mail/user/%d', $account->usr->id),
                sprintf('mail/mailbox/%d', $mailbox->id),
            ],
            [
                'type' => 'mailbox.synced',
                'mailboxId' => $mailbox->id,
                'accountId' => $account->id,
                'specialUse' => $mailbox->specialUse,
            ],
        );
    }

    public function publishAccountSynced(Account $account): void
    {
        $this->publish(
            [sprintf('mail/user/%d', $account->usr->id)],
            [
                'type' => 'account.synced',
                'accountId' => $account->id,
            ],
        );
    }

    /**
     * Publish, and never let a hub outage become the caller's failure.
     *
     * A notification is the doorbell, not the delivery. The work this
     * announces has already happened and been committed by the time we get
     * here, so an exception thrown out of this method does not undo it — it
     * just fails a handler that succeeded, and Messenger then retries work
     * that was already done.
     *
     * That is not theoretical: a Mercure hub that was briefly unreachable
     * failed a whole SyncAccountMessage and sent it back for retry, mail
     * synced and all, because the update saying "your mailbox changed" could
     * not be sent. Same reasoning, same wording, as JmapPushSubscriber.
     *
     * The cost of swallowing is a screen that does not tick until its next
     * refresh. The cost of not swallowing is repeating the sync.
     */
    /**
     * @param list<string>        $topics
     * @param array<string,mixed> $data
     */
    private function publish(array $topics, array $data): void
    {
        try {
            $this->hub->publish(new Update(topics: $topics, data: json_encode($data, JSON_THROW_ON_ERROR)));
        } catch (\Throwable $e) {
            $this->logger->log(
                ThrowableSeverity::level($e, \Psr\Log\LogLevel::WARNING),
                'SyncNotifier: publish failed',
                ['type' => $data['type'] ?? null, 'error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
