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

            if (null === $issue) {
                continue;
            }

            $issues[]                       = $issue;
            $deadGrants[(int) $account->id] = $issue->id;
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

        foreach ($this->calendars->findForUser($user) as $calendar) {
            $issue = $this->calendar($calendar, $deadGrants);

            if (null !== $issue) {
                $issues[] = $issue;
            }
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
     * A calendar that has stopped filling in.
     *
     * Attributed to the account's dead grant when there is one. Three calendars
     * failing because one Google sign-in expired is one problem with three
     * symptoms, and the repair for all three is the single reconnect button on
     * the account above — offering three more of them would imply three
     * separate round trips through Google.
     *
     * Still listed rather than swallowed: the user's question is "why is my
     * Feiertage calendar empty", and an account-level card alone does not
     * answer it.
     *
     * @param array<int, string> $deadGrants account id => the issue id naming it
     */
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
            return null;
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
     * @param list<array{id: string, class: string, error: string|null, failedAt: mixed}> $failed
     */
    private function summarise(array $failed): ?string
    {
        $tally = [];

        foreach ($failed as $row) {
            $class = $row['class'];
            $short = substr((string) strrchr($class, '\\'), 1);
            $key   = ('' !== $short ? $short : $class) . ': ' . ($row['error'] ?? '—');

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
