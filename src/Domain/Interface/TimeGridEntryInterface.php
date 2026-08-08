<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use DateTimeImmutable;

/**
 * The three questions a time-grid asks of anything it draws.
 *
 * It exists so that DayGridLayout — the lane assignment, the clipping at
 * midnight, the wall-clock arithmetic that is the whole of this application's
 * DST handling — is written once and used by both calendars. The authenticated
 * one places OccurrenceCluster objects, which carry events; the public shared
 * page places SharedOccurrence objects, which are redactions with no event
 * behind them at all. Neither may know about the other, and the layout must not
 * know about either.
 *
 * **Nothing here can leak, and that is by construction rather than by care.**
 * The interface asks for two instants and a boolean and has no method that could
 * answer with a title, so a layout written against it cannot put one anywhere —
 * which is what makes it safe for the shared page to use the same class the
 * owner's own calendar does. See SharedOccurrence for why the redaction lives in
 * the DTO rather than in the renderer.
 *
 * Both ends are nullable because a stored occurrence's are: a row with no start
 * is a data fault the grid survives by putting it at the top of the day rather
 * than a condition to raise on a page somebody is trying to read. The layout
 * owns that fallback, because only it knows which day is being drawn.
 */
interface TimeGridEntryInterface
{
    public function gridStartsAt(): ?DateTimeImmutable;

    public function gridEndsAt(): ?DateTimeImmutable;

    /**
     * Whether the time axis applies to this at all.
     *
     * An all-day entry has no time to be positioned at — it would land on the
     * midnight line as a block of zero height, which is both invisible and a
     * lie — so the grid lifts these into a band above the hours. See DayGrid.
     */
    public function occupiesWholeDay(): bool;
}
