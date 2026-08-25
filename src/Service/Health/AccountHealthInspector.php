<?php

declare(strict_types=1);

namespace App\Service\Health;

use App\Domain\DTO\Health\HealthFact;
use App\Domain\DTO\Health\HealthIssue;
use App\Domain\DTO\Health\HealthReport;
use App\Domain\DTO\Health\HealthRepair;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Health\HealthIssueKind;
use App\Domain\Enum\Health\HealthSeverity;
use App\Domain\Enum\PushHealth;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\AccountRepository;
use App\Service\Monitoring\QueueMonitor;
use App\Service\Push\PushRenewalRecord;
use App\Service\Push\PushSubscriptionRegistry;

/**
 * What is wrong with this user's accounts, and what would fix it.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * Every condition below was ALREADY detected and ALREADY stored before this
 * class was written. Account::$oauthLastRefreshError is written by
 * OAuthTokenManager and was read by no template and no controller;
 * Calendar::$lastSyncError is written by CalendarSyncService and only ever
 * rendered as a line of grey text; IntegrationTokenManager's docblock says the
 * settings list "can say reconnect this" and nothing did. The install this was
 * built from had one dead Google grant that had been stopping mail for two
 * days, and the only place it appeared was five thousand log lines an admin
 * would have had to go looking for.
 *
 * So this reads, it does not detect. Nothing here calls a provider, opens a
 * socket or refreshes a token — it is a handful of indexed queries over state
 * the sync layer already wrote, which is what lets it back a Twig global that
 * renders on every authenticated page.
 *
 * ── The false-positive rule ──────────────────────────────────────────────────
 * A health page that cries wolf is a health page with a "don't show this again"
 * checkbox six months later. Every check below fires on a STORED FAILURE, never
 * on an inference: a calendar with no lastSyncError is healthy even if it has
 * never synced (it may mirror nothing yet), and an account with no
 * oauthLastRefreshError is healthy even if it has not been refreshed in a
 * month (nothing needed refreshing). The one check that reads a derived state
 * — push — asks PushSubscriptionRegistry, which owns that judgement already.
 */
final readonly class AccountHealthInspector
{
    /**
     * How many calendar names a grouped card lists before it stops.
     *
     * A card is not a list. Six is enough to recognise the set at a glance on
     * the accounts people actually have, and the remainder is counted rather
     * than hidden.
     */
    private const int NAMES_IN_A_GROUP = 6;

    /**
     * Consecutive failed syncs before the health page says anything.
     *
     * Three, because a mailbox is polled often enough that one failure is
     * usually a dropped connection and three in a row is not. The count resets
     * on the first success, so this is "it is still failing", never "it failed
     * once, a while ago".
     */
    private const int SYNC_FAILURES_BEFORE_REPORTING = 3;

    public function __construct(
        private AccountRepository        $accounts,
        private CalendarRepository       $calendars,
        private IntegrationRepository    $integrations,
        private PushSubscriptionRegistry $pushRegistry,
        private QueueMonitor             $queueMonitor,
        private PushRenewalRecord        $renewals,
    ) {
    }

    /**
     * @param bool $includeInfrastructure whether to look at the failure
     *                                    transport, which is instance-wide
     *                                    rather than per-user — see
     *                                    abandonedWork()
     */
    public function inspect(User $user, bool $includeInfrastructure = false): HealthReport
    {
        $issues = [];

        // Read once and iterated twice. This backs a Twig global that renders on
        // every authenticated page, so asking the repository a second time for
        // the same rows would be a second query per page for nothing.
        $accounts = $this->accounts->findForUserOrdered($user);

        // Accounts first, and their ids kept, because everything else on the
        // page may turn out to be downstream of one of them.
        $deadGrants = [];

        foreach ($accounts as $account) {
            $issue = $this->accountGrant($account);

            if (null !== $issue) {
                $issues[]                       = $issue;
                $deadGrants[(int) $account->id] = $issue->id;

                // A dead grant makes every scope question moot: the consent
                // screen is unreachable until the account is signed in to
                // again, and the reconnect above is that same trip. Two cards
                // asking for one journey is how a page stops being read.
                continue;
            }

            $scope = $this->calendarPermission($account)
                ?? $this->mailboxSync($account)
                ?? $this->serverAlert($account);

            if (null !== $scope) {
                $issues[] = $scope;

                // Registered as a CAUSE, not merely reported. Every calendar on
                // this account is failing for exactly this reason, and without
                // this each one raised its own red card offering "Try syncing
                // now" — a button whose entire behaviour is to fail, three
                // times over, with nothing on the page connecting any of them
                // to the permission that was never granted.
                $deadGrants[(int) $account->id] = $scope->id;
            }
        }

        // A separate pass rather than the same loop: push is only asked about
        // once the dead grants are known, because an account with no working
        // token has no push worth reporting separately.
        foreach ($accounts as $account) {
            $issue = $this->push($account);

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        // Calendars failing for a reason already on the page are collected per
        // cause rather than listed one by one. Three calendars on one account
        // are three symptoms of a single missing permission, and three cards
        // saying so is a page that buries its own answer — see calendarGroup().
        $blocked = [];

        foreach ($this->calendars->findForUser($user) as $calendar) {
            $cause = $this->causeFor($calendar, $deadGrants);

            if (null !== $cause) {
                $blocked[$cause][] = $calendar;

                continue;
            }

            $issue = $this->calendar($calendar, $deadGrants);

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($blocked as $cause => $calendars) {
            // One calendar is not a group. It keeps its own card, which names
            // it — "Familie has stopped syncing" tells the user more than "1
            // calendar is not syncing" ever could.
            $issues[] = 1 === count($calendars)
                ? $this->calendar($calendars[0], $deadGrants)
                : $this->calendarGroup($calendars, (string) $cause);
        }

        foreach ($this->integrations->findForUserOrdered($user) as $integration) {
            $issue = $this->integration($integration);

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        if (true === $includeInfrastructure) {
            $issue = $this->abandonedWork();

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return HealthReport::of($issues);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The brand name to put in "Taking you to …".
     *
     * MailProvider::label() is explicit that these are not translated — Gmail is
     * Gmail in every locale — so this returns the brand and the catalogue owns
     * the sentence around it.
     *
     * Null when the stored provider string maps to no case, which is a
     * migration artefact rather than a user-visible condition. The caller then
     * picks the sentence with no brand in it rather than rendering "Taking you
     * to …" with a hole where the name should be.
     */
    private function providerLabel(Account $account): ?string
    {
        if (null === $account->oauthProvider) {
            return null;
        }

        return MailProvider::tryFrom($account->oauthProvider)?->label();
    }

    /**
     * The dead grant: the provider will not renew the sign-in any more.
     *
     * Fires on the stored error alone. OAuthTokenManager writes it on every
     * failed refresh and clears it on every successful one, so a non-null value
     * means the most recent attempt failed — which is precisely the condition
     * where nothing will work until the user re-consents.
     *
     * A missing refresh token is the same problem arriving by a different road
     * (OAuthTokenManager::refresh() throws for it before it ever reaches the
     * provider), so it is folded in here rather than given a kind of its own:
     * the sentence shown and the button offered would have been identical.
     */
    /**
     * What is wrong with one account, ignoring everything downstream of it.
     *
     * The account-scoped subset of [inspect], for callers that hold an Account and cannot afford a
     * whole report. Both checks behind it read fields on the entity and issue no query, which is
     * what makes this safe on a path that runs per request — SessionBuilder publishes it in every
     * JMAP Session so that a phone can say *why* an account has gone quiet instead of simply
     * receiving no mail from it.
     *
     * Grant before push, because they are cause and effect: an account whose OAuth grant has died
     * has no working token, so its push is broken as a consequence and reporting both would be two
     * cards for one problem. `push()` already declines to answer for such an account; the ordering
     * here says the same thing where a reader will see it.
     *
     * Calendars, integrations and abandoned queue work are deliberately absent. None of them is a
     * property of the mail account, and a client asking "can I still receive mail here" must not be
     * told no because a calendar sync failed.
     */
    public function inspectAccount(Account $account): ?HealthIssue
    {
        return $this->accountGrant($account)
            ?? $this->calendarPermission($account)
            ?? $this->push($account);
    }

    private function accountGrant(Account $account): ?HealthIssue
    {
        if (AuthType::OAuth2->value !== $account->authType) {
            return null;
        }

        $stored  = $account->oauthLastRefreshError;
        $missing = null === $account->oauthRefreshToken;

        if (null === $stored && false === $missing) {
            return null;
        }

        $providerLabel = $this->providerLabel($account);

        return new HealthIssue(
            id: 'account-' . $account->id,
            kind: HealthIssueKind::AccountReconnect,
            severity: HealthSeverity::Critical,
            subject: $account->email,
            titleParams: ['%account%' => $account->email],
            bodyParams: ['%account%' => $account->email],
            repairs: [
                new HealthRepair(
                    route: 'app_health_reconnect',
                    routeParams: ['id' => $account->id],
                    labelKey: 'settings.health.repair.reconnect.label',
                    promiseKey: 'settings.health.repair.reconnect.promise',
                    // The promise names the mailbox the user must sign in as.
                    // That is the sentence the identity guard exists to make
                    // good on, so it has to say WHICH address rather than
                    // "the same one".
                    promiseParams: ['%account%' => $account->email],
                    // Named, because this one leaves the app. A blank second
                    // while the browser negotiates with Google is the exact
                    // moment a person presses the button again, and "Taking you
                    // to Gmail…" is both the reassurance and the reason not to.
                    pendingKey: null !== $providerLabel
                        ? 'settings.health.pending.reconnect'
                        : 'settings.health.pending.reconnect_generic',
                    pendingParams: null !== $providerLabel
                        ? ['%provider%' => $providerLabel]
                        : [],
                ),
            ],
            // Shown behind a disclosure only. MailProvider::calendarScopes()
            // makes the case: "invalid_grant" is not something to put in front
            // of a person as an explanation, but a self-hoster comparing notes
            // with a forum post needs to be able to find it.
            detail: $stored,
        );
    }

    /**
     * The account connected, and without the calendar permission it asked for.
     *
     * Ranked BELOW accountGrant() and that order matters: a dead refresh token
     * makes every scope question moot, and telling somebody to tick a box on a
     * consent screen they cannot currently reach is advice that wastes a trip.
     *
     * Only ever raised on a positive answer. `grantsCalendarAccess()` returns
     * null when the provider sent no scope back — which per OAuth 2.0 means the
     * grant matched the request — and null is also what an account connected
     * before this was recorded looks like. Neither is evidence of anything
     * missing, and reporting on a null would put a permanent warning on every
     * account that predates the column.
     */
    private function calendarPermission(Account $account): ?HealthIssue
    {
        if (AuthType::OAuth2->value !== $account->authType) {
            return null;
        }

        $provider = MailProvider::tryFrom((string) $account->oauthProvider);

        if (null === $provider) {
            return null;
        }

        // Two ways to learn the same thing, and both are needed.
        //
        // The recorded grant is the direct evidence and covers the case before
        // anything has gone wrong — but it is null for every account connected
        // before it was recorded, and those are exactly the accounts already
        // suffering from this.
        //
        // A permanently refused export is the indirect evidence, and it is
        // available immediately: `insufficientPermissions` on a batchModify is
        // Gmail saying the grant cannot write. That is what makes this useful
        // on an install that has been broken for weeks rather than only on the
        // next account somebody connects.
        // And a calendar that failed for the same reason is the THIRD, which is
        // the one that works on an install already broken. It needs no refresh
        // to have happened and no further export to be refused: the calendars
        // stored the provider's own refusal the first time they failed, and it
        // has been sitting in the database ever since.
        //
        // This is the gap that mattered. Recording the granted scope only helps
        // accounts connected afterwards, and waiting for a refusal only helps
        // if something tries to write. Somebody looking at three broken
        // calendars right now had the evidence on screen and nothing read it.
        $missing = $provider->missingScopes($account->oauthGrantedScopes);

        // A KNOWN-GOOD GRANT ENDS THE QUESTION.
        //
        // The indirect evidence below is historical: a calendar keeps the error
        // it failed with until it next succeeds, and an export refusal is
        // cleared by the next export that works. Neither is a statement about
        // the grant as it stands now — so once the granted scopes have been
        // recorded and nothing is missing from them, the old refusals are the
        // past and this card has nothing to say.
        //
        // Without this the card could not be cleared by doing what it asked.
        // Reconnecting records a complete grant, and the stale calendar error
        // raised the card again on the very next page load.
        if ([] === $missing) {
            return null;
        }

        $missing ??= [];

        // The recorded grant is null on every account connected before it was
        // recorded — which is every account already suffering from this — so
        // two indirect signals stand in for it.
        //
        // A permanently refused export, but only when the refusal is ABOUT
        // scopes: `insufficientPermissions` on a batchModify is Gmail saying
        // the grant cannot write, while a tenant policy refusing REST access is
        // a different problem with a different answer, and this card's wording
        // fits only the first.
        $refused = true === $provider->looksLikeScopeRefusal($account->exportRefusedReason)
            ? $account->exportRefusedReason
            : null;

        // And a calendar that failed the same way, which is the signal that
        // works on an install already broken: it needs no refresh to have
        // happened and nothing to have tried to write, because the calendars
        // stored the provider's own refusal the first time they failed.
        $refusedByCalendar = null;

        if ([] === $missing && null === $refused) {
            foreach ($this->calendars->findMirroredForAccount($account) as $calendar) {
                if (true === $provider->looksLikeScopeRefusal($calendar->lastSyncError)) {
                    $refusedByCalendar = $calendar->lastSyncError;

                    break;
                }
            }
        }

        if ([] === $missing && null === $refused && null === $refusedByCalendar) {
            return null;
        }

        $providerLabel = $this->providerLabel($account);

        return new HealthIssue(
            id: 'account-scope-' . $account->id,
            kind: HealthIssueKind::AccountScopeMissing,
            // A warning, not a failure: mail works, and it will keep working.
            // Calling this critical would put it beside "your account has
            // stopped receiving" and teach people to ignore both.
            severity: HealthSeverity::Warning,
            subject: $account->email,
            titleParams: ['%account%' => $account->email],
            bodyParams: ['%account%' => $account->email],
            repairs: [
                new HealthRepair(
                    route: 'app_health_reconnect',
                    routeParams: ['id' => $account->id],
                    labelKey: 'settings.health.repair.grant_calendar.label',
                    promiseKey: 'settings.health.repair.grant_calendar.promise',
                    promiseParams: ['%account%' => $account->email],
                    pendingKey: null !== $providerLabel
                        ? 'settings.health.pending.reconnect'
                        : 'settings.health.pending.reconnect_generic',
                    pendingParams: null !== $providerLabel
                        ? ['%provider%' => $providerLabel]
                        : [],
                ),
            ],
            // Behind a disclosure, like the refresh error: nobody needs to
            // read this to act, and the one person debugging a tenant policy
            // needs it exactly. The provider's own refusal first when there is
            // one — it names the call that was turned away.
            detail: $refused ?? $refusedByCalendar ?? $account->oauthGrantedScopes,
        );
    }

    /**
     * The mailbox has stopped syncing, and the sign-in is not the reason.
     *
     * Reached only when accountGrant() and calendarPermission() both had
     * nothing to say, which is what keeps this from being a second card about
     * a problem already explained. "Sign in again" and "your mail server keeps
     * refusing us" are different repairs.
     *
     * The threshold is the point. A dropped connection is not news and a page
     * that reports every one of them is a page that stops being read; several
     * attempts in a row is a different statement, and only the second one is
     * worth a card. One success clears the count entirely.
     */
    private function mailboxSync(Account $account): ?HealthIssue
    {
        if (true !== $account->isActive) {
            return null;
        }

        if (null === $account->lastSyncError || $account->syncFailureCount < self::SYNC_FAILURES_BEFORE_REPORTING) {
            return null;
        }

        return new HealthIssue(
            id: 'account-sync-' . $account->id,
            kind: HealthIssueKind::AccountSyncFailing,
            severity: HealthSeverity::Critical,
            subject: $account->email,
            titleParams: ['%account%' => $account->email],
            bodyParams: ['%account%' => $account->email],
            // No repair button. There is no honest one: the fault is at the
            // other end, and a "try again" here would be a button whose whole
            // behaviour is to wait for the next scheduled sync to fail too.
            // The detail below is what someone can actually act on.
            repairs: [],
            // The evidence, not a second verdict. A mailbox that fails to sync
            // and has re-listed itself six times this week is a different story
            // from one that has never had to — the first is falling behind the
            // window its provider keeps, and that is somewhere to look.
            facts: $this->resyncFacts($account),
            detail: $account->lastSyncError,
        );
    }

    /**
     * How often this account has had to start its sync over, if ever.
     *
     * Nothing when it never has, which is the common case and reads better as
     * absence than as "0 times". HealthFact's own docblock makes the opposite
     * argument for dates — a null there renders as "never" rather than a blank,
     * because an empty cell reads as a bug — and that holds because the fact is
     * always relevant once its card is up. This one is only relevant when it
     * has happened.
     *
     * @return list<HealthFact>
     */
    private function resyncFacts(Account $account): array
    {
        if (0 === $account->fullResyncCount) {
            return [];
        }

        return [
            new HealthFact(
                labelKey: 'settings.health.fact.last_full_resync',
                at: $account->lastFullResyncAt,
                labelParams: ['%count%' => $account->fullResyncCount],
            ),
        ];
    }

    /**
     * What the mail server asked us to tell the user.
     *
     * Passed through verbatim, because it is not ours to paraphrase: the server
     * wrote a sentence for a person to read, and the RFC requires it be shown.
     * The card's own wording says only where it came from.
     *
     * Last of the account checks, and that is not a ranking of importance —
     * an alert usually explains something rather than replacing it, and a card
     * that says "your mailbox is full" is more useful under one that says mail
     * has stopped arriving than instead of it.
     */
    private function serverAlert(Account $account): ?HealthIssue
    {
        if (null === $account->imapServerAlert || '' === trim($account->imapServerAlert)) {
            return null;
        }

        return new HealthIssue(
            id: 'server-alert-' . $account->id,
            kind: HealthIssueKind::ServerAlert,
            severity: HealthSeverity::Warning,
            subject: $account->email,
            titleParams: ['%account%' => $account->email],
            bodyParams: [
                '%account%' => $account->email,
                '%alert%'   => $account->imapServerAlert,
            ],
            // Nothing to offer. plMail cannot empty somebody's mailbox or
            // change their password, and a button that did nothing would be
            // worse than none.
            repairs: [],
        );
    }

    /**
     * Several calendars, one cause, one card.
     *
     * The shape this replaces: an account without calendar permission put a red
     * card on the page for every calendar on it, each offering a retry that
     * could only fail, and nothing anywhere connecting the four of them to the
     * one box that was never ticked.
     *
     * The names are listed rather than counted alone, because "which of my
     * calendars went dark" is the actual question and a number does not answer
     * it. Capped, because an account can carry dozens and a card is not a list.
     *
     * @param list<Calendar> $calendars all failing for the same reason
     */
    private function calendarGroup(array $calendars, string $causedBy): HealthIssue
    {
        $names = array_map(static fn (Calendar $calendar): string => (string) $calendar->name, $calendars);

        sort($names);

        $shown     = array_slice($names, 0, self::NAMES_IN_A_GROUP);
        $remaining = count($names) - count($shown);

        // `+3` rather than a translated "and 3 more", because this class emits
        // keys and parameters and never translated text — that separation is
        // what lets every string on the page be a translator's to change. A
        // bare plus and a numeral read the same in every language this ships.
        $listed = implode(', ', $shown) . (0 < $remaining ? ' +' . $remaining : '');

        $params = ['%count%' => count($names), '%calendars%' => $listed];

        return new HealthIssue(
            id: 'calendars-blocked-' . $causedBy,
            kind: HealthIssueKind::CalendarsBlocked,
            severity: HealthSeverity::Warning,
            subject: $listed,
            titleParams: $params,
            bodyParams: $params,
            // No repair: it belongs to the card this one is downstream of.
            repairs: [],
            causedBy: $causedBy,
        );
    }

    /**
     * The issue already on the page that explains this calendar, if there is one.
     *
     * Shared by the grouping above and by calendar() below rather than worked
     * out twice: the two would disagree the moment one of them learned about a
     * new kind of cause, and the symptom would be a calendar counted in a group
     * AND given its own card.
     *
     * @param array<int, string> $deadGrants account id => the issue id naming it
     */
    private function causeFor(Calendar $calendar, array $deadGrants): ?string
    {
        if (null === $calendar->lastSyncError || CalendarRole::Remote !== $calendar->role) {
            return null;
        }

        $accountId = $calendar->account?->id;

        return null !== $accountId ? ($deadGrants[(int) $accountId] ?? null) : null;
    }

    private function calendar(Calendar $calendar, array $deadGrants): ?HealthIssue
    {
        if (null === $calendar->lastSyncError) {
            return null;
        }

        if (CalendarRole::Remote !== $calendar->role) {
            return null;
        }

        $accountId = $calendar->account?->id;
        $causedBy  = null !== $accountId ? ($deadGrants[(int) $accountId] ?? null) : null;

        // A consequence is not a second emergency. It keeps its place in the
        // list under its cause, but it does not add to the count in the topbar
        // and it does not paint another red card.
        $severity = null !== $causedBy ? HealthSeverity::Warning : HealthSeverity::Critical;

        $repairs     = [];
        $awaitingKey = null;

        if (null === $causedBy) {
            // Only offer a retry where a retry could plausibly work. When the
            // grant is dead, "try again" is a button whose entire behaviour is
            // to fail, and the reconnect above is the real answer.
            $repairs[] = new HealthRepair(
                route: 'app_health_calendar_resync',
                routeParams: ['id' => $calendar->id],
                labelKey: 'settings.health.repair.calendar_resync.label',
                promiseKey: 'settings.health.repair.calendar_resync.promise',
                csrfTokenId: 'health_calendar_' . $calendar->id,
                // "Starting", not "Syncing" and certainly not "Synced". The
                // controller dispatches a message and redirects; what the press
                // achieved by the time the page comes back is that the sync has
                // been started, and the label says exactly that much.
                pendingKey: 'settings.health.pending.calendar_resync',
            );

            if (true === $calendar->isAwaitingRequestedSync()) {
                // Already asked, nothing back yet. The card says so instead of
                // showing the button, because offering it here is offering a
                // second dispatch of a message already on the queue.
                //
                // The repair above is still built rather than dropped, and that
                // is what makes the surface recoverable: the template renders it
                // hidden behind the waiting line, so when the worker reports a
                // failure — or never reports at all — the button that comes back
                // carries a real CSRF token and a translated label that no
                // amount of JavaScript could have produced on its own.
                $awaitingKey = 'settings.health.awaiting.calendar_resync';
            }
        }

        return new HealthIssue(
            id: 'calendar-' . $calendar->id,
            kind: HealthIssueKind::CalendarSyncFailing,
            severity: $severity,
            subject: $calendar->name,
            titleParams: ['%calendar%' => $calendar->name],
            bodyParams: ['%calendar%' => $calendar->name],
            repairs: $repairs,
            causedBy: $causedBy,
            detail: $calendar->lastSyncError,
            awaitingKey: $awaitingKey,
        );
    }

    /**
     * Push that is not delivering — and WHICH of the two ways that happens.
     *
     * ── Why this is two kinds and one repair ─────────────────────────────────
     * The button is the same for both, because re-registering is idempotent and
     * fixes either. The card is not, because the two failures have different
     * causes and the user's next move differs completely:
     *
     *   - Lapsed: the registration expired. Renewal did not run. Look at the
     *     scheduler — `app:push:renew` is a daily cron and a scheduler that has
     *     stopped firing reports nothing at all, so this card is the only place
     *     it will ever surface.
     *   - Degraded: the registration is alive and unexpired, and mail arrived
     *     that it failed to announce. For Gmail the fault is in the Pub/Sub leg
     *     — Gmail → Cloud Pub/Sub → the endpoint — which plMail cannot see and
     *     the Cloud console can.
     *
     * The one thing that made them indistinguishable was showing neither of the
     * dates behind them, so both carry their evidence; see $facts.
     *
     * Never reported for an account whose grant is dead: with no working token
     * there is no push to speak of, and the reconnect is the repair for both.
     */
    private function push(Account $account): ?HealthIssue
    {
        if (null !== $account->oauthLastRefreshError) {
            return null;
        }

        $health = $this->pushRegistry->health($account);

        if (false === $health->needsRepair()) {
            return null;
        }

        $kind = PushHealth::Lapsed === $health
            ? HealthIssueKind::PushLapsed
            : HealthIssueKind::PushDegraded;

        return new HealthIssue(
            id: 'push-' . $account->id,
            kind: $kind,
            severity: $kind->defaultSeverity(),
            subject: $account->email,
            titleParams: ['%account%' => $account->email],
            bodyParams: ['%account%' => $account->email],
            repairs: [
                // The existing control, not a second one. AccountPushController
                // already has the repair the accounts pane uses, under its own
                // CSRF id; a parallel route here would be a second thing to
                // keep correct for no gain.
                new HealthRepair(
                    route: 'settings_account_push_repair',
                    routeParams: ['id' => $account->id],
                    labelKey: 'settings.health.repair.push_resubscribe.label',
                    promiseKey: 'settings.health.repair.push_resubscribe.promise',
                    csrfTokenId: 'account_push_' . $account->id,
                    pendingKey: 'settings.health.pending.push_resubscribe',
                ),
            ],
            facts: $this->pushFacts($account),
        );
    }

    /**
     * The three dates that answer "is this lapsed or silent?" without a
     * database client.
     *
     * Read in the order somebody diagnoses in: when the registration runs out
     * (past means lapsed, and the template tints it), when it last actually
     * delivered (never, or long before the mailbox last changed, means the
     * delivery leg is broken), and when renewal last ran (long ago means the
     * scheduler is why the first line says what it says).
     *
     * The renewal run is instance-wide rather than per-account — one scheduled
     * command covers every account — and it is repeated on each card rather
     * than hoisted somewhere else on purpose: it is the line that explains the
     * expiry directly above it, and a fact the reader has to go and find is a
     * fact they will not connect.
     *
     * @return list<HealthFact>
     */
    private function pushFacts(Account $account): array
    {
        $manager = $this->pushRegistry->resolve($account);

        $facts = [
            new HealthFact(
                labelKey: 'settings.health.fact.push_expires',
                at: $manager?->expiresAt($account),
                noneKey: 'settings.health.fact.not_registered',
                // The only deadline among the three, and the only one whose
                // being in the past means anything.
                alarmWhenPast: true,
            ),
        ];

        // Gmail only, because only Gmail records it — and only Gmail needs it.
        // Graph validates its callback URL when the subscription is made, so a
        // live Graph subscription is one Microsoft confirmed it can reach and
        // there is no silent-delivery failure for this line to diagnose.
        // Showing "never delivered" for an Outlook account would be inventing a
        // symptom out of a column that provider never writes.
        if (true === $account->isGmail()) {
            $facts[] = new HealthFact(
                labelKey: 'settings.health.fact.push_last_delivered',
                at: $account->gmailLastPushAt,
                noneKey: 'settings.health.fact.never_delivered',
            );
        }

        $facts[] = new HealthFact(
            labelKey: 'settings.health.fact.push_last_renewal',
            at: $this->renewals->lastRunAt(),
            noneKey: 'settings.health.fact.never_run',
        );

        return $facts;
    }

    /**
     * A file-store connection whose token could not be renewed.
     *
     * IntegrationTokenManager already records the failure and already composes
     * "X needs to be reconnected"; this is the list its docblock says should
     * exist. The reconnect is the integration OAuth flow that is already there,
     * which upserts onto the same row — see IntegrationTokenManager::
     * storeAuthorization(), whose docblock is explicit that reconnecting
     * updates rather than duplicates.
     */
    private function integration(Integration $integration): ?HealthIssue
    {
        if (null === $integration->lastError) {
            return $this->integrationScope($integration);
        }

        return new HealthIssue(
            id: 'integration-' . $integration->id,
            kind: HealthIssueKind::IntegrationReconnect,
            severity: HealthSeverity::Warning,
            subject: $integration->provider->label(),
            titleParams: ['%connection%' => $integration->provider->label()],
            bodyParams: ['%connection%' => $integration->provider->label()],
            repairs: [
                new HealthRepair(
                    route: 'app_integration_oauth_connect',
                    routeParams: ['provider' => $integration->provider->value],
                    labelKey: 'settings.health.repair.integration_reconnect.label',
                    promiseKey: 'settings.health.repair.integration_reconnect.promise',
                    pendingKey: 'settings.health.pending.reconnect',
                    pendingParams: ['%provider%' => $integration->provider->label()],
                ),
            ],
            detail: $integration->lastError,
        );
    }

    /**
     * A connection that works and was given less than it asked for.
     *
     * Reached only when the connection is otherwise healthy, and that ordering
     * is the point: a broken connection has its own card with the same repair
     * on it, and telling somebody to re-grant a permission on a service that is
     * not currently reachable wastes the trip.
     *
     * Silent on a null, always. A connection made before the granted scopes
     * were recorded has none, and so does one whose provider returned no
     * `scope` — which per OAuth 2.0 means the grant matched the request.
     * Reporting on either would put a permanent warning on every connection
     * that predates the column.
     */
    private function integrationScope(Integration $integration): ?HealthIssue
    {
        $missing = $integration->provider->missingScopes($integration->oauthGrantedScopes);

        if (null === $missing || [] === $missing) {
            return null;
        }

        $label = $integration->provider->label();

        return new HealthIssue(
            id: 'integration-scope-' . $integration->id,
            kind: HealthIssueKind::IntegrationScopeMissing,
            severity: HealthSeverity::Warning,
            subject: $label,
            titleParams: ['%connection%' => $label],
            bodyParams: ['%connection%' => $label],
            repairs: [
                new HealthRepair(
                    route: 'app_integration_oauth_connect',
                    routeParams: ['provider' => $integration->provider->value],
                    labelKey: 'settings.health.repair.integration_grant.label',
                    promiseKey: 'settings.health.repair.integration_grant.promise',
                    promiseParams: ['%connection%' => $label],
                    pendingKey: 'settings.health.pending.reconnect',
                    pendingParams: ['%provider%' => $label],
                ),
            ],
            // The scopes as the service spelled them, behind a disclosure.
            detail: $integration->oauthGrantedScopes,
        );
    }

    /**
     * Work that exhausted its retries and was put aside.
     *
     * Aggregated to a count, never a list. The install this was built from had
     * five hundred and three of these and they were four distinct problems;
     * rendering five hundred rows would bury that.
     *
     * ── Why this is admin-gated ──────────────────────────────────────────────
     * The failure transport is instance-wide. Attributing an envelope to a user
     * means deserialising it and walking whatever id it happens to carry back
     * to an account, for every row — expensive, and wrong for any message that
     * belongs to no user. Rather than guess, this is offered only to the role
     * that can already see the whole queue and act on it, which on a
     * self-hosted install is the person reading the page anyway. A non-admin
     * loses nothing they could have acted on: the account card above already
     * tells them their mail has stopped, which is their half of it.
     */
    private function abandonedWork(): ?HealthIssue
    {
        $failed = $this->queueMonitor->failedMessages();
        $count  = count($failed);

        if (0 === $count) {
            return null;
        }

        return new HealthIssue(
            id: 'queue-abandoned',
            kind: HealthIssueKind::QueueWorkAbandoned,
            severity: HealthSeverity::Warning,
            subject: '',
            titleParams: ['%count%' => $count],
            bodyParams: ['%count%' => $count],
            repairs: [
                new HealthRepair(
                    route: 'app_health_queue_retry',
                    routeParams: [],
                    labelKey: 'settings.health.repair.queue_retry.label',
                    promiseKey: 'settings.health.repair.queue_retry.promise',
                    csrfTokenId: 'health_queue_retry',
                    pendingKey: 'settings.health.pending.queue_retry',
                ),
                new HealthRepair(
                    route: 'app_health_queue_discard',
                    routeParams: [],
                    labelKey: 'settings.health.repair.queue_discard.label',
                    promiseKey: 'settings.health.repair.queue_discard.promise',
                    csrfTokenId: 'health_queue_discard',
                    destructive: true,
                    // The destructive one gets a pending label too, but it is
                    // reached only after data-turbo-confirm has been answered —
                    // Turbo raises the dialog before it starts the submission,
                    // so nothing about this state makes the button easier to
                    // fire. It is the confirmation that guards it; this only
                    // stops a second press of an already-confirmed purge.
                    pendingKey: 'settings.health.pending.queue_discard',
                ),
            ],
            detail: $this->summarise($failed),
        );
    }

    /**
     * The distinct failures behind the count, worst-repeated first.
     *
     * @param list<array{id: string, class: string, error: string|null, failedAt: mixed, accountId: int|null}> $failed
     */
    private function summarise(array $failed): ?string
    {
        // Which mailbox each one belonged to, resolved in a single query rather
        // than one per row. Without this the card could say "50 jobs were given
        // up on" and not that all fifty were one account whose grant could not
        // write — which was the entire answer, and was sitting in the envelopes
        // the whole time.
        $ids = array_values(array_unique(array_filter(
            array_column($failed, 'accountId'),
            static fn (?int $id): bool => null !== $id,
        )));

        $labels = [];

        foreach ([] !== $ids ? $this->accounts->findBy(['id' => $ids]) : [] as $account) {
            $labels[(int) $account->id] = (string) $account->email;
        }

        $tally = [];

        foreach ($failed as $row) {
            $class = $row['class'];
            $short = substr((string) strrchr($class, '\\'), 1);
            $who   = null !== $row['accountId'] ? ($labels[$row['accountId']] ?? null) : null;

            $key = ('' !== $short ? $short : $class)
                . (null !== $who ? ' (' . $who . ')' : '')
                . ': ' . ($row['error'] ?? '—');

            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        if ([] === $tally) {
            return null;
        }

        arsort($tally);

        $lines = [];

        foreach (array_slice($tally, 0, 5, true) as $key => $n) {
            $lines[] = $n . ' × ' . $key;
        }

        return implode("\n", $lines);
    }
}
