<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\User\User;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells a user's open tabs that their insights moved.
 *
 * ── Why not a third method on SyncNotifier ──────────────────────────────────
 * It would have fit: same hub, same `mail/user/{id}` topic, same envelope. It
 * is a separate class because of what the two are ABOUT. SyncNotifier speaks
 * for the mail sync — its two updates both carry an account or a mailbox id,
 * and its own class doc is a note about what the sync handlers do and do not
 * dispatch alongside them. This speaks for the insight pipeline, which runs
 * as one post-ingest step among several and can be switched off per extractor
 * per user without the sync noticing. Widening SyncNotifier would have made
 * `App\Service\Mail` the home of an announcement no mail service makes, and
 * given ExtractInsightsStep a dependency on the sync's notifier to say
 * something the sync has no opinion about.
 *
 * The payload carries no ids on purpose — no insight id, no count. The
 * receiver is a lazy turbo-frame whose only move is to re-request
 * app_insight_pane, and that request re-derives everything: the off-switch,
 * the dismissal, the window, the cap. Anything sent here would be a second
 * copy of a rule that already has one home ({@see InsightPane}), and the first
 * time the two disagreed the strip would show a row the fragment would not.
 */
final readonly class InsightNotifier
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publishInsightsChanged(User $user): void
    {
        try {
            $this->hub->publish(new Update(
                topics: [
                    sprintf('mail/user/%d', $user->id),
                ],
                data: json_encode([
                    'type' => 'insights.changed',
                ]),
            ));
        } catch (\Throwable $e) {
            // A notification is the doorbell, not the delivery. The work this
            // announces is already committed, so throwing here cannot undo it —
            // it only fails a caller that succeeded, and Messenger then retries
            // work already done. A brief Mercure outage did exactly that to a
            // whole account sync. The cost of swallowing is a screen that waits
            // for its next refresh.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'InsightNotifier: publish failed',
                ['error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
