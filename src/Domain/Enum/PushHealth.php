<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How healthy an account's push delivery actually is.
 *
 * The Degraded state exists because Gmail and Graph fail differently, and the
 * Gmail failure is invisible without it:
 *
 *   - Graph validates the notification URL synchronously when the subscription
 *     is created, so a broken endpoint fails loudly at subscribe time.
 *   - Gmail's users.watch only registers interest in a Pub/Sub TOPIC. The push
 *     SUBSCRIPTION that forwards from that topic to /gmail/push lives in Google
 *     Cloud and is entirely outside plMail's control. watch() therefore
 *     succeeds perfectly while nothing is ever delivered.
 *
 * A registered watch that has not received a push in a long time is the only
 * signal available for that, which is what gmailLastPushAt is for.
 */
enum PushHealth: string
{
    /** Registered and delivering. */
    case Active = 'active';

    /** Registered, but nothing has arrived recently — likely misconfigured. */
    case Degraded = 'degraded';

    /** Not registered; the account is on scheduled polling. */
    case Inactive = 'inactive';

    public function isOn(): bool
    {
        return self::Inactive !== $this;
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
            self::Active   => 'bg-emerald-100/80 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
            self::Degraded => 'bg-amber-100/80 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
            self::Inactive => 'bg-zinc-100/80 text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active   => 'fa-bolt',
            self::Degraded => 'fa-triangle-exclamation',
            self::Inactive => 'fa-rotate',
        };
    }
}
