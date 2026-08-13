<?php

declare(strict_types=1);

namespace App\Domain\DTO\Health;

use DateTimeImmutable;

/**
 * One dated fact behind a health verdict — "watch expires: …", "last delivered:
 * …", "last renewal run: …".
 *
 * ── Why the card needs these at all ──────────────────────────────────────────
 * The judgement on its own is not actionable. "Push is degraded" was true for a
 * day and a half on a live install while its owner could not tell, from the
 * app, whether the watch had expired or was alive and simply not delivering —
 * two problems with two completely different places to go and look. The verdict
 * says which one it is; these say what it was read off, so somebody can check
 * the reasoning instead of taking it on faith or opening a database client.
 *
 * ── Why a DTO of a key and a date, rather than a string ─────────────────────
 * The same rule HealthIssue keeps: the inspector runs in workers and commands
 * as well as in a request, so it holds translation KEYS and never rendered
 * text. A date is worse than text in that respect, not better — "13 Aug 2026,
 * 04:00" is a formatting decision that belongs to the locale doing the reading,
 * so the value stays a DateTimeImmutable and Twig's `format_datetime` renders
 * it where the locale is known.
 *
 * $at being null is a fact too, and usually the loudest one: no renewal has
 * ever been recorded, nothing has ever been delivered. It renders as
 * $noneKey rather than as a blank, because an empty cell reads as a bug.
 */
final readonly class HealthFact
{
    public function __construct(
        /** Translation key for the label, e.g. 'settings.health.fact.watch_expires'. */
        public string $labelKey,
        public ?DateTimeImmutable $at = null,
        /** What to say instead of a date when $at is null. */
        public string $noneKey = 'settings.health.fact.never',
        /**
         * Whether this date being in the past is itself the problem.
         *
         * Only true for a deadline. "Registration expires" in the past IS the
         * verdict and the template tints it so the eye lands on the line that
         * proves the card. "Last notification received" is in the past by
         * definition — every date that describes something which already
         * happened is — and tinting those would paint the whole card amber and
         * teach the reader that the colour means nothing.
         */
        public bool $alarmWhenPast = false,
    ) {
    }

    public function isKnown(): bool
    {
        return null !== $this->at;
    }

    /**
     * Whether this fact is a deadline that has gone by — the one line worth
     * colouring. See $alarmWhenPast.
     */
    public function isOverdue(): bool
    {
        return true === $this->alarmWhenPast
            && null !== $this->at
            && $this->at <= new DateTimeImmutable();
    }
}
