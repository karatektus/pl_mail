<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Domain\Enum\Ai\SemanticSkipReason;

/**
 * What the search page says about the meaning pass, in one object.
 *
 * FOUR STATES THAT MUST NOT LOOK ALIKE
 * ────────────────────────────────────
 *  · It did not run, and why — {@see $skipped}.
 *  · It ran against vectors from a model that is no longer the search model,
 *    so it searched what matches and ignored the rest — {@see modelChanged()}.
 *  · It ran while the mailbox is still being indexed, so "nothing extra" is
 *    "not yet" rather than "there was nothing" — {@see indexing()}.
 *  · It ran over a finished index and added nothing — {@see foundNothingExtra()}.
 *
 * The last two are the pair that matters. Semantic search over a mailbox that
 * is eight per cent embedded is not semantic search, it is a trap: the results
 * look like the feature's best effort, they are a fraction of it, and the state
 * is temporary and knowable. A person told "4,120 of 48,900" waits; a person
 * told nothing concludes the feature does not work and stops using it.
 */
final readonly class SemanticSearchReport
{
    /**
     * Close enough to finished that a percentage is noise rather than news.
     *
     * Not 100. A mailbox holds messages that will never produce a vector — an
     * empty body, an attachment-only note, something the model refused — and
     * they do not disappear from the eligible count. Demanding the last one
     * would leave "99% complete" printed under every search forever, which is
     * how a progress notice turns into furniture nobody reads.
     */
    private const int COMPLETE_PERCENT = 99;

    public function __construct(
        /** Null when the pass ran. */
        public ?SemanticSkipReason $skipped,
        /** Messages of THIS mailbox holding a vector from the model search is using. */
        public int $embedded,
        /** Messages of this mailbox that could hold one. */
        public int $eligible,
        /** Vectors this mailbox holds from some other model or width. */
        public int $stale,
        /** Results on the page in hand that only the meaning pass found. */
        public int $extra,
    ) {
    }

    public function ran(): bool
    {
        return null === $this->skipped;
    }

    /** Whether there is anything worth printing under the search box at all. */
    public function speaks(): bool
    {
        if (null !== $this->skipped) {
            return $this->skipped->tellsTheUser();
        }

        // A pass that ran over a finished index and found something extra says
        // so per row, with a badge. A line saying the same thing again would be
        // noise on every search this feature ever helps with.
        return $this->modelChanged() || $this->indexing() || $this->foundNothingExtra();
    }

    /**
     * The search model has changed and nothing under the new one exists yet.
     *
     * Deliberately not "some vectors are stale". Half a mailbox re-indexed is
     * an ordinary backfill in progress and {@see indexing()} already says so
     * with a number; this is the state where the answer to "why did meaning
     * find nothing" is not "wait" but "somebody changed the model".
     */
    public function modelChanged(): bool
    {
        return $this->ran() && 0 === $this->embedded && $this->stale > 0;
    }

    /** Still filling in, so an incomplete answer is expected rather than final. */
    public function indexing(): bool
    {
        return $this->ran() && $this->eligible > 0 && false === $this->complete() && false === $this->modelChanged();
    }

    /** It ran, over an index that is done, and the words had already found everything. */
    public function foundNothingExtra(): bool
    {
        return $this->ran() && 0 === $this->extra && $this->complete() && false === $this->modelChanged();
    }

    public function complete(): bool
    {
        if (0 === $this->eligible) {
            return true;
        }

        return $this->embedded >= $this->eligible || $this->percent() >= self::COMPLETE_PERCENT;
    }

    /** Whole per cent, rounded DOWN so that 99.6% of a huge mailbox is not "100". */
    public function percent(): int
    {
        if (0 === $this->eligible) {
            return 100;
        }

        return (int) floor($this->embedded * 100 / $this->eligible);
    }
}
