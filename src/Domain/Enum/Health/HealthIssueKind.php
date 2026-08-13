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
     * The push registration is alive and unexpired, and mail is arriving by
     * some route other than the push that was meant to announce it. For Gmail
     * that isolates the fault to the Pub/Sub leg — the part of the path that
     * lives in Google Cloud rather than in plMail.
     *
     * Kept distinct from PushLapsed even though the repair button is the same,
     * because the two send somebody to different places: this one to their
     * Cloud console, the other to their scheduler.
     */
    case PushDegraded = 'push_degraded';

    /**
     * The push registration's own expiry has passed. Not an inference and not a
     * threshold — a stored date that is in the past.
     *
     * Its cause is almost always that renewal stopped running, which is the
     * failure nothing else in the app can report: `app:push:renew` is scheduled
     * daily, and a scheduler that has stopped firing raises no error because
     * failing to run is not an event.
     */
    case PushLapsed = 'push_lapsed';

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
            // ── Why push is no longer a Notice ────────────────────────────────
            // Notice deliberately does not light the topbar indicator, and that
            // was the right call for a verdict reached by inference: the old
            // check fired after 36 hours of quiet, which on a mailbox that
            // simply had no mail was a false alarm, and a false alarm on the
            // indicator is how the indicator stops being read.
            //
            // Neither of these is an inference any more. Lapsed is a stored
            // expiry that has passed; Degraded now requires evidence that the
            // mailbox changed and push did not say so. A check that cannot cry
            // wolf can afford the indicator — and the reason to spend it is
            // that push dying is exactly what went unnoticed for a day and a
            // half on a live install.
            //
            // Warning and not Critical: polling is still delivering the mail,
            // so "your mail has stopped" would be false. Warning is the level
            // for "something is broken and the data still flows", which is
            // precisely this.
            self::PushDegraded         => HealthSeverity::Warning,
            self::PushLapsed           => HealthSeverity::Warning,
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
            // fa-tower-broadcast, not fa-bolt-slash: the latter is a Pro icon
            // and is not in the Free build this app ships, so it rendered as
            // nothing at all — an empty square where every other card has a
            // glyph. Caught only once the card became prominent enough to look
            // at. Broadcast-with-nothing-arriving is the better metaphor
            // anyway; the lapsed card keeps the hourglass, which is the one
            // difference the eye catches before reading either title.
            self::PushDegraded         => 'fa-tower-broadcast',
            self::PushLapsed           => 'fa-hourglass-end',
            self::IntegrationReconnect => 'fa-plug-circle-exclamation',
            self::QueueWorkAbandoned   => 'fa-inbox',
        };
    }
}
