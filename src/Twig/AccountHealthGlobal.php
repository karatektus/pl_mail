<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Health\AccountHealthInspector;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether anything about this user's accounts needs their attention.
 *
 * Shaped after LogAlertGlobal, which solves the same problem one floor up: the
 * topbar renders from a layout on every authenticated page, so there is no
 * controller in the middle to thread a variable through.
 *
 * ── Not admin-only, unlike LogAlertGlobal ────────────────────────────────────
 * That one is gated because the log browser is behind ROLE_ADMIN, and an alarm
 * about something you cannot open is just an alarm. This is the opposite case:
 * these are the user's OWN accounts and every repair offered is one they are
 * allowed to run. The infrastructure check is the single exception and is asked
 * for separately — see the includeInfrastructure flag.
 *
 * ── Not dismissible, unlike LogAlertGlobal ───────────────────────────────────
 * There is no counterpart to User::$logsSeenAt here, deliberately. "Seen" is
 * the right model for a log, which is a record of things that happened; it is
 * the wrong model for a condition that is still true. A dot the user can clear
 * while their mail is still not arriving would be worse than no dot at all,
 * because the next time it appears they will already have learned that clearing
 * it is what you do. This one goes away when the problem does.
 *
 * Per-request memoised, including the healthy answer, so a topbar that grew a
 * second reference to it would not cost a second round of queries.
 */
final class AccountHealthGlobal implements ResetInterface
{
    private bool $resolved = false;

    /** @var array{tone: string, count: int}|null */
    private ?array $attention = null;

    public function __construct(
        private readonly AccountHealthInspector $inspector,
        private readonly Security               $security,
    ) {
    }

    /**
     * Worker-mode hygiene, for the reason LogAlertGlobal spells out: FrankenPHP
     * keeps this service alive between requests, so without this the first
     * answer a worker computes is the answer every later request — and every
     * later USER — receives. Here that would mean showing one user's broken
     * account to another, so it is a correctness requirement and not tidiness.
     */
    public function reset(): void
    {
        $this->resolved  = false;
        $this->attention = null;
    }

    /**
     * The tone and count for the indicator, or null when there is nothing to
     * say.
     *
     * The count is root causes worth interrupting for, not issues — see
     * HealthReport::indicatorCount(). One dead Google grant reads as "1" even
     * when it has taken three calendars and five hundred jobs down with it,
     * because that is how many things the user has to do.
     *
     * @return array{tone: string, count: int}|null
     */
    public function getAttention(): ?array
    {
        if (true === $this->resolved) {
            return $this->attention;
        }

        $this->resolved = true;
        $user           = $this->security->getUser();

        if (false === $user instanceof User) {
            return null;
        }

        // Infrastructure is left out here on purpose. The failure transport is
        // instance-wide and reading it means deserialising envelopes; that is
        // a fine cost on the settings page somebody navigated to, and not one
        // to pay on every page render for a badge.
        $report = $this->inspector->inspect($user);
        $count  = $report->indicatorCount();
        $tone   = $report->indicatorTone();

        if (0 === $count || null === $tone) {
            return null;
        }

        $this->attention = ['tone' => $tone, 'count' => $count];

        return $this->attention;
    }
}
