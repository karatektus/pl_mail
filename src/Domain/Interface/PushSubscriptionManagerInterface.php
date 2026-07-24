<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Enum\PushHealth;
use App\Entity\Account;
use DateTimeImmutable;

/**
 * Provider-agnostic push registration.
 *
 * Same shape as AccountSyncerInterface and MailSenderInterface: implementations
 * are tagged, a registry picks the one that supports the account, and callers
 * never name a provider. Gmail (users.watch + Cloud Pub/Sub) and Microsoft
 * (Graph subscriptions) are the two implementations; IMAP has no push and is
 * simply unsupported.
 *
 * Every method is best-effort by contract. Push is an optimisation on top of
 * scheduled delta polling, never a replacement for it, so a false return means
 * "stay on polling" rather than "error".
 */
interface PushSubscriptionManagerInterface
{
    public function supports(Account $account): bool;

    /**
     * Register push for the account. Returns false when push could not be
     * established — the caller should leave the account polling.
     */
    public function subscribe(Account $account): bool;

    /**
     * Extend an existing registration, recreating it if renewal fails.
     */
    public function renew(Account $account): bool;

    /**
     * Tear down remotely and locally. Remote errors are swallowed: a
     * registration we can no longer delete lapses on its own, and blocking
     * account deletion on it would be worse.
     */
    public function unsubscribe(Account $account): void;

    public function needsRenewal(Account $account): bool;

    public function expiresAt(Account $account): ?DateTimeImmutable;

    public function health(Account $account): PushHealth;

    /**
     * Whether the deployment is configured well enough for push to work at all
     * (public HTTPS URL, Pub/Sub topic set, …). Checked before subscribing so
     * misconfiguration produces a clear local message rather than an opaque
     * remote failure.
     */
    public function isConfigured(): bool;
}
