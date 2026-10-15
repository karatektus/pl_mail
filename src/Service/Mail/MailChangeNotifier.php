<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\ThrowableSeverity;
use App\Infrastructure\Mercure\UserUpdate;
use App\Repository\User\UserRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;

/**
 * Tells a user's open tabs that their mail changed, whoever changed it.
 *
 * ── The gap this closes ─────────────────────────────────────────────────────
 * The sidebar's numbers are patched from /mail/sidebar/counts, and for a long
 * time what made a tab ask was a list of places that remembered to say so:
 * the sync publishes `mailbox.synced`, and each button that writes — the row's
 * envelope, the toolbar, the move menu — called announceWrite() in the
 * browser. Every path that was not on the list had a stale badge, and the list
 * was never complete. The composer was not on it, so a saved draft did not
 * raise Drafts and a discarded one did not lower it. And nothing in a browser
 * can be on it for a change the browser did not make: the send that leaves ten
 * seconds after Send was pressed happens in a worker, and a mail read on the
 * phone happens in another client altogether. Drafts stayed at 1 after the mail
 * had gone until something else happened to move it.
 *
 * ── Why here ────────────────────────────────────────────────────────────────
 * There is already one place every change to a user's mail passes through:
 * StateManager, which records it for JMAP because a phone's `/changes` is only
 * correct if nothing is left out. JmapPushSubscriber drains it at the end of
 * each request and each handled message and tells the phones. This is the same
 * drain telling the browsers, so "did the sidebar hear about it" stops being a
 * property of each feature and becomes a property of the change having been
 * recorded at all — which it must be anyway.
 *
 * ── What it carries ─────────────────────────────────────────────────────────
 * A nudge and one number: the highest change sequence in what was drained. The
 * tab compares it with the sequence its last counts answer was read at
 * (SidebarCounts puts it in the payload) and does nothing when it is not
 * newer. That is what stops the tab that made the change — which has usually
 * re-read its counts already, on the strength of the response header
 * MailChangedHeaderSubscriber sets — from asking a second time for the same
 * numbers.
 *
 * No counts in the payload. They are per user and the drain is per account;
 * computing them here would put the sidebar's queries into every worker
 * message of an import, for tabs that may not be open.
 */
final readonly class MailChangeNotifier
{
    public function __construct(
        private HubInterface    $hub,
        private UserRepository  $users,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int|string, array<string, string>> $changed accountId => [objectType => state],
     *                                                           as StateManager::drainDirty() gives it
     */
    public function publish(array $changed): void
    {
        if ([] === $changed) {
            return;
        }

        try {
            $owners = $this->users->findOwnersOfAccounts(array_map('intval', array_keys($changed)));
            $latest = [];

            foreach ($changed as $accountId => $states) {
                $userId = $owners[(int) $accountId] ?? null;

                if (null === $userId) {
                    continue;
                }

                $latest[$userId] = max($latest[$userId] ?? 0, ...array_map('intval', array_values($states)));
            }

            foreach ($latest as $userId => $sequence) {
                $this->hub->publish(UserUpdate::create(
                    topics: [sprintf('mail/user/%d', $userId)],
                    data: json_encode(['type' => 'mail.changed', 'sequence' => $sequence], JSON_THROW_ON_ERROR),
                ));
            }
        } catch (\Throwable $e) {
            // The doorbell, not the delivery: the change is committed, and a
            // hub that is down or absent — a supported way to run this — must
            // not turn a request that worked into one that failed. The cost is
            // what there was before: the tab hears at its next sync or poll.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'MailChangeNotifier: publish failed',
                ['error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
