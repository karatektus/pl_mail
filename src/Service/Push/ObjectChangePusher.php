<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Jmap\Protocol\StateChangeBuilder;
use App\Jmap\Push\PushSenderRegistry;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Tells a user's devices that their calendar or notes changed: a StateChange
 * naming `CalendarEvent` and/or `Note`, so the phone refreshes them — and its
 * home-screen widgets — without being opened.
 *
 * **It carries the mail states too.** On FCM every StateChange shares one
 * collapse key, so a phone that was asleep keeps only the newest; were this
 * one to name only the calendar it could hide a mail change that arrived just
 * before it. With each account's current Email/Mailbox/Thread states inside,
 * the phone compares them with its own as it always does, and syncs mail only
 * if they moved.
 *
 * Sent to every deliverable device regardless of the types it subscribed to:
 * apps subscribed before these types existed would otherwise never hear them,
 * and a browser ignores any StateChange that does not name Email (public/sw.js).
 * Devices that asked for arrivals only are left out — this is not new mail.
 */
final readonly class ObjectChangePusher
{
    private const array MAIL_TYPES = [JmapObjectType::Email, JmapObjectType::Mailbox, JmapObjectType::Thread];

    public function __construct(
        private UserRepository             $users,
        private AccountRepository          $accounts,
        private StateManager               $states,
        private PushSubscriptionRepository $subscriptions,
        private PushSenderRegistry         $senders,
        private StateChangeBuilder         $builder,
        private LoggerInterface            $logger,
    ) {
    }

    /**
     * @param array<int, list<string>> $byUser userId => changed types (CalendarEvent, Note)
     */
    public function push(array $byUser): void
    {
        if ([] === $byUser || false === $this->senders->anyConfigured()) {
            return;
        }

        $token = (string) (int) (microtime(true) * 1000);

        foreach ($byUser as $userId => $types) {
            try {
                $user = $this->users->find($userId);
                if (null === $user) {
                    continue;
                }

                $accounts = $this->accounts->findActiveForUserOrdered($user);
                if ([] === $accounts) {
                    continue;
                }

                $changed = [];
                foreach ($accounts as $account) {
                    foreach (self::MAIL_TYPES as $type) {
                        $changed[(string) $account->id][$type->value] = $this->states->stateFor((int) $account->id, $type);
                    }
                }
                // Announced on the first account, which is the one a phone
                // treats as the calendar's; the token only has to move.
                foreach ($types as $type) {
                    $changed[(string) $accounts[0]->id][$type] = $token;
                }

                $payload = $this->builder->format($changed);

                foreach ($this->subscriptions->findDeliverableForUser((int) $userId) as $subscription) {
                    if (true === $subscription->arrivalsOnly) {
                        continue;
                    }
                    $sender = $this->senders->for($subscription);
                    if (null !== $sender && true === $sender->isConfigured()) {
                        $sender->send($subscription, $payload);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Push: could not announce a calendar or notes change', [
                    'userId' => $userId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }
}
