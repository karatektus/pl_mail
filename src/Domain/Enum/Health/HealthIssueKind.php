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
     * The grant WORKS, and is narrower than what was asked for.
     *
     * Distinct from AccountReconnect on purpose: nothing is broken, mail is
     * arriving, and the token will refresh happily for years. What is missing
     * is a permission the user was offered and did not give — Google's consent
     * screen ticks sensitive scopes individually, and a Microsoft tenant can be
     * configured to withhold one.
     *
     * It has to be its own card because the alternative is silence. The
     * handshake succeeds, so nothing fails at connect time; the shortfall
     * surfaces days later as calendars that "stopped syncing" with a 403, which
     * reads as a fault in plMail rather than a permission nobody granted.
     *
     * The repair is the same reconnect, with a different thing to do on the
     * consent screen — which is why the wording, not the button, is the point.
     */
    case AccountScopeMissing = 'account_scope_missing';

    /**
     * The mailbox itself has stopped syncing, repeatedly, for a reason that is
     * not the sign-in.
     *
     * A mail account recorded nothing about failing until this existed. A
     * calendar has had a message, a count and a backoff for as long as it has
     * synced; the mailbox — the thing the application is for — had a
     * `last_synced_at` column that nothing wrote, so an IMAP server refusing
     * connections for a week looked identical to one that synced a minute ago.
     *
     * Raised on a COUNT rather than on the last failure, and that is the whole
     * of what keeps it honest: a dropped connection at three in the morning is
     * not news, and a page that says so about every blip is a page nobody
     * reads. Several attempts in a row failing is a different statement.
     *
     * Ranked below the grant cards, which explain a sync failure when they
     * apply — "sign in again" and "your mail server is refusing us" are
     * different repairs and the user should be shown the one that fits.
     */
    case AccountSyncFailing = 'account_sync_failing';

    /**
     * A calendar whose sync answers the same way every time — see
     * CalendarSyncPermanentException. Surfaced per calendar rather than rolled
     * into the account, because "reconnect and allow calendar access" and
     * "reconnect" are different repairs and the user has to know which
     * calendars went dark.
     */
    case CalendarSyncFailing = 'calendar_sync_failing';

    /**
     * SEVERAL calendars on one account, all stopped for the same reason, and
     * that reason already has a card of its own further up the page.
     *
     * One card because it is one problem. A Google account whose grant is dead,
     * or which was never given calendar permission, takes every calendar on it
     * down at once — and listing them individually produced four red cards each
     * offering "Try syncing now", a button whose entire behaviour there is to
     * fail. The page buried its own answer under its own symptoms.
     *
     * No repair of its own, deliberately: the repair is on the card this one
     * points at, and offering a second button for the same trip is how a page
     * teaches people to stop reading it. A single blocked calendar keeps its
     * own card instead — "Familie has stopped syncing" says more than "1
     * calendar is not syncing" ever could.
     */
    case CalendarsBlocked = 'calendars_blocked';

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
            // Warning, not Critical: mail is arriving and will keep arriving.
            // Sitting this beside "your account has stopped receiving" would
            // teach people to read past both.
            self::AccountScopeMissing  => HealthSeverity::Warning,
            // Critical: this one means new mail is not arriving, which is the
            // application not doing its job rather than a part of it missing.
            self::AccountSyncFailing   => HealthSeverity::Critical,
            self::CalendarSyncFailing  => HealthSeverity::Critical,
            // A consequence, never a second emergency: the cause above is the
            // thing to act on, and this must not add to the topbar count.
            self::CalendarsBlocked     => HealthSeverity::Warning,
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
            // A permission that was not given, rather than a link that broke.
            self::AccountScopeMissing  => 'fa-calendar-day',
            self::AccountSyncFailing   => 'fa-inbox',
            self::CalendarSyncFailing  => 'fa-calendar-xmark',
            self::CalendarsBlocked     => 'fa-calendar-xmark',
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
