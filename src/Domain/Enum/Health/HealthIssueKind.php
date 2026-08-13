<?php

declare(strict_types=1);

namespace App\Domain\Enum\Health;

/**
 * The kinds of thing that can be wrong with a user's accounts.
 *
 * Deliberately a closed set. The app already detects every one of these — the
 * gap this feature closes is that it wrote them down and then never read them
 * back — so a kind exists here only where there is a stored signal to read and
 * a repair to offer. "Something in the logs looks odd" is not a kind: it has no
 * repair, and an issue with no button is a worry with no way to act on it.
 *
 * Each kind names its own translation keys rather than the inspector building
 * them by hand. The strings are the entire feature — the user's problem is that
 * nothing told them anything — so they get a stable, greppable home.
 */
enum HealthIssueKind: string
{
    /**
     * The stored refresh token no longer works. Mail, calendar and every
     * background job for the account stop together, which is why this is the
     * one kind that can be the sole cause of thousands of log lines.
     */
    case AccountReconnect = 'account_reconnect';

    /**
     * A calendar whose sync answers the same way every time — see
     * CalendarSyncPermanentException. Surfaced per calendar rather than rolled
     * into the account, because "reconnect and allow calendar access" and
     * "reconnect" are different repairs and the user has to know which
     * calendars went dark.
     */
    case CalendarSyncFailing = 'calendar_sync_failing';

    /**
     * Registered for push but nothing is arriving, so the account is really on
     * polling. Mail still lands; it lands late.
     */
    case PushDegraded = 'push_degraded';

    /** A file-store connection whose token could not be renewed. */
    case IntegrationReconnect = 'integration_reconnect';

    /** Background work that exhausted its retries and was put aside. */
    case QueueWorkAbandoned = 'queue_work_abandoned';

    /**
     * The severity a kind carries when nothing else is known about it.
     *
     * The inspector may lower it — a calendar that is failing only because its
     * account's grant is dead is a consequence, not a second emergency — but it
     * never raises it.
     */
    public function defaultSeverity(): HealthSeverity
    {
        return match ($this) {
            self::AccountReconnect     => HealthSeverity::Critical,
            self::CalendarSyncFailing  => HealthSeverity::Critical,
            self::IntegrationReconnect => HealthSeverity::Warning,
            self::QueueWorkAbandoned   => HealthSeverity::Warning,
            self::PushDegraded         => HealthSeverity::Notice,
        };
    }

    /** Headline — what is wrong, in the user's terms. */
    public function titleKey(): string
    {
        return 'settings.health.issue.' . $this->value . '.title';
    }

    /** What it means for their mail, and what they will get back by fixing it. */
    public function bodyKey(): string
    {
        return 'settings.health.issue.' . $this->value . '.body';
    }

    public function icon(): string
    {
        return match ($this) {
            self::AccountReconnect     => 'fa-link-slash',
            self::CalendarSyncFailing  => 'fa-calendar-xmark',
            self::PushDegraded         => 'fa-bolt-slash',
            self::IntegrationReconnect => 'fa-plug-circle-exclamation',
            self::QueueWorkAbandoned   => 'fa-inbox',
        };
    }
}
