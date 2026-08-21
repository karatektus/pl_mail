<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Entity\Insight\MailInsight;
use App\Entity\User\User;
use App\Repository\Insight\MailInsightRepository;
use DateTimeImmutable;

/**
 * Whether the mail list wears a strip of insights above it, and which ones.
 *
 * One place for a rule three readers need — the fragment that renders the
 * strip, the dismiss endpoint that has to know whether anything is left to
 * show, and the tests. Spread across those it would be three derivations of
 * the same sentence, which is the duplication OwnershipVoter's class doc
 * argues against at length.
 *
 * ── This reverses a decision that was written down ───────────────────────────
 * HappeningSoonController rejected exactly this panel, and its reasons were
 * good ones: a fourth region would take width from panes that have none to
 * give, and it would query on every mailbox load for a list that is empty for
 * most people on most days. Both are answered rather than ignored.
 *
 * The strip takes HEIGHT and not width — it is a band above the list, not a
 * column beside it — so no pane loses a pixel it had. And it costs nothing on
 * a mailbox load, because it is not rendered with the page: it arrives in a
 * lazy turbo-frame, so the query runs after the mail is on screen and only for
 * people who have the feature on. When there is nothing to show it renders
 * nothing at all, which is the common case and has to stay free.
 *
 * What is genuinely different from the panel that was rejected is the audience.
 * Happening Soon answers "what is coming up?" for somebody who went looking;
 * this answers "your parcel is out for delivery" for somebody who did not, and
 * that only works where the mail already is.
 */
final readonly class InsightPane
{
    /**
     * How many rows the strip may carry.
     *
     * Three, because the strip's whole claim is that it costs less attention
     * than the mail it sits above — and a fourth row starts pushing the list
     * off a laptop screen, at which point it is competing with the inbox
     * rather than introducing it. What does not fit is not lost: the radar
     * panel behind the topbar indicator holds the full list, and it is the
     * thing to open when three is not the answer.
     */
    public const int MAX_ROWS = 3;

    public function __construct(
        private MailInsightRepository $insights,
    ) {
    }

    /**
     * The rows to render, or none at all — an empty list means the strip does
     * not exist for this request, whichever of the three reasons applies.
     *
     * @return list<MailInsight> soonest first
     */
    public function rowsFor(User $user, DateTimeImmutable $now): array
    {
        if (true === $this->isDisabledFor($user)) {
            return [];
        }

        $rows = $this->insights->upcomingForUser($user, $now, limit: self::MAX_ROWS);

        if ([] === $rows) {
            return [];
        }

        return true === $this->isDismissed($user, $rows) ? [] : $rows;
    }

    /** The settings switch, which is off-until-switched-on's opposite: on until switched off. */
    public function isDisabledFor(User $user): bool
    {
        return true === $user->getSetting(User::SETTING_INSIGHT_PANE_DISABLED, false);
    }

    /**
     * Waved away, and nothing has happened since that it has not already
     * shown.
     *
     * The comparison is against each row's createdAt — when the FACT was
     * first landed — rather than against its updatedAt. A carrier revising an
     * estimate rewrites the row it already showed you, and treating that as
     * news would bring the strip back for a parcel the user dismissed twenty
     * minutes ago, over and over, for the whole of its journey.
     *
     * @param list<MailInsight> $rows
     */
    public function isDismissed(User $user, array $rows): bool
    {
        $dismissedAt = $this->dismissedAt($user);

        if (null === $dismissedAt) {
            return false;
        }

        foreach ($rows as $row) {
            if ($row->createdAt > $dismissedAt) {
                return false;
            }
        }

        return true;
    }

    /** When the strip was last waved away, or null if it never was. */
    public function dismissedAt(User $user): ?DateTimeImmutable
    {
        $stored = $user->getSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT);

        if (false === is_string($stored) || '' === $stored) {
            return null;
        }

        // A value that is not a date is treated as no dismissal rather than as
        // an error: this bag is free-form jsonb, and a strip that refuses to
        // render because of a malformed preference is a worse failure than a
        // strip that comes back once.
        try {
            return new DateTimeImmutable($stored);
        } catch (\Exception) {
            return null;
        }
    }
}
