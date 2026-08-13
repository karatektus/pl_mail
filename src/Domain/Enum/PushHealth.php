<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How healthy an account's push delivery actually is.
 *
 * The unhealthy states exist because Gmail and Graph fail differently, and the
 * Gmail failure is invisible without them:
 *
 *   - Graph validates the notification URL synchronously when the subscription
 *     is created, so a broken endpoint fails loudly at subscribe time.
 *   - Gmail's users.watch only registers interest in a Pub/Sub TOPIC. The push
 *     SUBSCRIPTION that forwards from that topic to /gmail/push lives in Google
 *     Cloud and is entirely outside plMail's control. watch() therefore
 *     succeeds perfectly while nothing is ever delivered.
 *
 * ── Why "broken" is two cases and not one ────────────────────────────────────
 * They have different causes, different evidence and different things to check,
 * and collapsing them into one Degraded state is what left a user unable to
 * tell, from the app, which of them had happened to them:
 *
 *   - Lapsed: the registration's own expiry has passed. This is a FACT read off
 *     a stored timestamp, true at any hour, and it means renewal did not run —
 *     app:push:renew is scheduled daily and a scheduler that is down logs
 *     nothing at all, so nothing else would ever say so.
 *   - Degraded: the registration is alive and unexpired, and mail is arriving
 *     by some other route than the push that was supposed to announce it. For
 *     Gmail that points at the Pub/Sub leg — a missing or misdirected push
 *     subscription, a topic the account cannot publish to — which is precisely
 *     the part of the path plMail cannot see.
 *
 * The repair happens to be the same for both (re-register; it is idempotent),
 * but "your watch expired" and "your watch is alive and nothing is coming
 * through it" send someone to two completely different places to look.
 */
enum PushHealth: string
{
    /** Registered and delivering. */
    case Active = 'active';

    /**
     * Registered and unexpired, but mail is demonstrably arriving without it —
     * see GmailPushSubscriptionManager::health() for what "demonstrably" means.
     */
    case Degraded = 'degraded';

    /**
     * The registration's own expiry has passed. Not an inference: nothing is
     * being delivered, and nothing will be until it is re-registered.
     */
    case Lapsed = 'lapsed';

    /** Not registered; the account is on scheduled polling. */
    case Inactive = 'inactive';

    public function isOn(): bool
    {
        return self::Inactive !== $this;
    }

    /**
     * Whether re-registering would be worth doing.
     *
     * Both broken states answer yes and the call is identical for them, which
     * is why every caller that used to compare against Degraded asks this
     * instead — `app:push:renew --repair` included. Missing one of the two
     * would leave exactly the accounts that need re-arming un-re-armed.
     */
    public function needsRepair(): bool
    {
        return self::Degraded === $this || self::Lapsed === $this;
    }

    public function translationKey(): string
    {
        return 'settings.accounts.push.health.' . $this->value;
    }

    /**
     * Tailwind classes for the status pill.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active             => 'bg-emerald-100/80 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
            self::Degraded, self::Lapsed => 'bg-amber-100/80 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
            self::Inactive           => 'bg-zinc-100/80 text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active   => 'fa-bolt',
            self::Degraded => 'fa-triangle-exclamation',
            // Not fa-bolt-slash — a Pro icon, absent from the Free build this
            // app ships, which renders as a blank where a glyph should be.
            self::Lapsed   => 'fa-hourglass-end',
            self::Inactive => 'fa-rotate',
        };
    }
}
