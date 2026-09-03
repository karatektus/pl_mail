<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\Helper\ThrowableSeverity;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tell an open thread that its summary has landed.
 *
 * A NUDGE AND NOT THE TEXT, which is the shape JobNotifier, RuleRunNotifier and
 * the insights strip all use, and here it earns its place twice over. The store
 * is the record, so a page that missed a message is one render behind rather
 * than wrong — and the thing not being carried is a paragraph of assertions
 * about somebody's mail. The topic is per-user and the subscriber cookie is
 * authenticated, so putting it on the bus would not be a leak; it would just be
 * mail on a channel that has never carried any, for no gain over "look again".
 */
final readonly class ThreadSummaryNotifier
{
    public function __construct(
        private HubInterface    $hub,
        private LoggerInterface $logger,
    ) {
    }

    /** @param 'ready'|'failed' $state */
    public function finished(int $userId, int $threadId, string $state): void
    {
        try {
            $this->hub->publish(new Update(
                topics: [sprintf('mail/user/%d', $userId)],
                data: (string) json_encode([
                    'type'     => 'summary.finished',
                    'threadId' => $threadId,
                    'state'    => $state,
                ]),
            ));
        } catch (\Throwable $e) {
            // A notification is the doorbell, not the delivery — JobNotifier's
            // words, and the same reasoning: the summary is already committed,
            // so throwing here cannot undo it. It would only fail a handler
            // that succeeded and have Messenger run a nine-minute model call a
            // second time. The cost of swallowing is a card that waits for its
            // next page load, where the stored summary is sitting anyway.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'ThreadSummaryNotifier: publish failed',
                ['threadId' => $threadId, 'error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
