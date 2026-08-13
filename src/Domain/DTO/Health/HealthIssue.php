<?php

declare(strict_types=1);

namespace App\Domain\DTO\Health;

use App\Domain\Enum\Health\HealthIssueKind;
use App\Domain\Enum\Health\HealthSeverity;

/**
 * One thing that is wrong, phrased for the person who has to fix it.
 *
 * Holds translation KEYS and their parameters, never rendered text: the
 * inspector runs in workers and commands as well as in a request, and a service
 * that formats English is a service that cannot be reused by the German one.
 *
 * ── On $causedBy ─────────────────────────────────────────────────────────────
 * The live install this was built from had ONE dead Google grant producing
 * three failing calendars, five hundred dead-lettered jobs and five thousand
 * log lines. Listing those as six equal emergencies would be an honest reading
 * of the stored state and a useless thing to show somebody. An issue that is
 * downstream of another names it here, so the surface can nest it under its
 * cause and the indicator can count causes rather than symptoms.
 */
final readonly class HealthIssue
{
    /**
     * @param string                  $id          stable per subject, so a repair
     *                                             can be addressed and a test can
     *                                             assert on one card
     * @param string                  $subject     the account address, calendar or
     *                                             connection name this is about —
     *                                             already user-facing, never a key
     * @param array<string, mixed>    $titleParams
     * @param array<string, mixed>    $bodyParams
     * @param list<HealthRepair>      $repairs     safe first; destructive ones flagged
     * @param string|null             $causedBy    id of the issue this follows from
     * @param string|null             $detail      the stored provider message, shown
     *                                             only behind a disclosure — see
     *                                             MailProvider::calendarScopes(): a
     *                                             raw error code is not an answer,
     *                                             but hiding it entirely leaves a
     *                                             self-hoster with nothing to search
     * @param string|null             $awaitingKey a repair that has ALREADY been
     *                                             asked for and has not reported
     *                                             back — the sentence to show in
     *                                             place of the button. See below
     * @param list<HealthFact>        $facts       the dated evidence the verdict
     *                                             was read off, shown on the card
     *                                             so the reasoning can be checked
     *                                             rather than believed — see
     *                                             HealthFact
     */
    public function __construct(
        public string          $id,
        public HealthIssueKind $kind,
        public HealthSeverity  $severity,
        public string          $subject,
        public array           $titleParams = [],
        public array           $bodyParams = [],
        public array           $repairs = [],
        public ?string         $causedBy = null,
        public ?string         $detail = null,
        public ?string         $awaitingKey = null,
        public array           $facts = [],
    ) {
    }

    /**
     * Whether the repair for this has been started and not yet answered.
     *
     * The reason this is a state of the ISSUE rather than of the button: the
     * repairs here are dispatches, not deeds. Pressing "Try syncing now" queues
     * a message and redirects, so by the time the page comes back the work is
     * neither done nor undone — and a card that renders an enabled button in
     * that gap is inviting a second press for work already on the queue, while
     * a card that renders "Synced" is lying.
     *
     * So the surface shows a third thing, and it has to survive the redirect
     * and a reload, which is why it is derived server-side from the stored
     * state (Calendar::isAwaitingRequestedSync) rather than held in the browser.
     * What ends it is the worker saying so — over Mercure on a page that is
     * open, or on the next render for a page that is not.
     */
    public function isAwaiting(): bool
    {
        return null !== $this->awaitingKey;
    }

    /** Whether this is a consequence of something else on the same page. */
    public function isConsequence(): bool
    {
        return null !== $this->causedBy;
    }

    /** @return list<HealthRepair> */
    public function safeRepairs(): array
    {
        return array_values(array_filter($this->repairs, static fn (HealthRepair $r): bool => false === $r->destructive));
    }

    /** @return list<HealthRepair> */
    public function destructiveRepairs(): array
    {
        return array_values(array_filter($this->repairs, static fn (HealthRepair $r): bool => true === $r->destructive));
    }
}
