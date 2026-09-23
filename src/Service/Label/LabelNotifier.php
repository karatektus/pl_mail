<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\User\User;
use App\Infrastructure\Mercure\UserUpdate;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;

/**
 * Tells a user's OTHER tabs that their label structure moved.
 *
 * ── The gap this closes ─────────────────────────────────────────────────────
 * Until v0.2.34 a mail-list navigation answered with a whole document, so the
 * sidebar was rebuilt on every folder click and a label renamed elsewhere
 * arrived on the next click by accident. That re-render cost about twelve
 * database queries per navigation and is gone; the named price was that a
 * rename made in another tab or on another device reached this tab's sidebar
 * on the next SYNC rather than on the next click — minutes, on an idle
 * mailbox, and never at all on one with no accounts configured.
 *
 * Everything else the sidebar shows was already pushed: the unread badges from
 * `ui--sidebar#refreshCounts()` on a sync event, and the label rows themselves
 * from {@see \App\Controller\Mail\LabelController::labelListsStream()} — which
 * re-renders the desktop sidebar, the mobile drawer and the settings list in
 * one turbo-stream response. The only thing missing was that that response went
 * back to the one tab that asked for it. This puts the same markup on the hub,
 * so every tab of that user applies it at once.
 *
 * ── Why it carries the rendered stream and not a "go and look" nudge ────────
 * Every other publisher in this application carries a nudge on purpose
 * ({@see \App\Service\Ai\ThreadSummaryNotifier} sets out the reasoning), and
 * the reason it does not apply here is the one that decides it: THE MARKUP HAS
 * ALREADY BEEN RENDERED. The request doing the publishing built it to answer
 * itself, and handing it on costs a string copy.
 *
 * A nudge would throw that away. Each listening tab would have to re-ask the
 * server for a label tree it had just built — a round trip plus roughly a dozen
 * queries EACH, on the same install whose whole v0.2.34 story was removing
 * exactly that cost from navigation. Re-adding it per tab per rename would be a
 * poor trade, and it would need a structure endpoint beside the counts one to
 * ask at all.
 *
 * The usual objection to carrying content — that the payload becomes a second
 * copy of a rule whose home is elsewhere, and the two drift — cannot happen
 * here, because the payload IS what that one home produced. This is the same
 * shape {@see \App\Service\Mail\SendOutcomeNotifier} publishes, for the same
 * reason, over the same topic.
 *
 * ── Scope ───────────────────────────────────────────────────────────────────
 * `mail/user/{id}`, exactly the topic the page subscribes to and exactly the
 * one the subscriber cookie authorises — see MercureCookieSubscriber and
 * MercureAuthController, both of which build it from the session's own user id
 * and nothing else, and `subscribe: []` in config/packages/mercure.yaml, which
 * makes a cookie minted without explicit topics grant nothing rather than
 * everything. Labels belong to a user, so one user's names can only reach a
 * browser holding a cookie minted for that user's id.
 *
 * ── What still waits for the next sync ──────────────────────────────────────
 * A label renamed through JMAP `Mailbox/set` — the Android app, or any other
 * connected client. That path has no rendered sidebar in hand and would have to
 * build one, which means putting a dozen sidebar queries and a Twig render into
 * a JSON API method. Left alone deliberately; the web paths are the ones the
 * v0.2.34 note was about.
 */
final readonly class LabelNotifier
{
    public function __construct(
        private HubInterface    $hub,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $stream the turbo-stream this user's tabs should apply.
     *                       Must NOT carry a toast: every tab gets this, and
     *                       only one of them pressed anything. See
     *                       LabelController::labelListsStream().
     */
    public function publishLabelsChanged(User $user, string $stream): void
    {
        $userId = $user->id;

        // An unsaved user has no id, and `mail/user/` is not a topic — whether
        // a subscription matched it would come down to the hub's pattern rules,
        // which is not a thing to discover in production. Same guard, same
        // reason, as SendOutcomeNotifier's ownerless account.
        if (null === $userId) {
            return;
        }

        try {
            $this->hub->publish(UserUpdate::create(
                topics: [sprintf('mail/user/%d', $userId)],
                data: json_encode([
                    'type'   => 'labels.changed',
                    'stream' => $stream,
                ], JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            // A notification is the doorbell, not the delivery. The label has
            // already been written and committed by the time we get here, so
            // throwing cannot undo it — it would only turn a rename that worked
            // into a 500, on an installation whose only fault is running
            // without a Mercure hub, which is a supported way to run this.
            //
            // The cost of swallowing is what the situation was before this
            // class existed: the other tabs wait for their next sync.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'LabelNotifier: publish failed',
                ['error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
