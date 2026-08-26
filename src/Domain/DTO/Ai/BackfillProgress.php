<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use DateTimeImmutable;

/**
 * Everything the panel says about the backfill, worked out once.
 *
 * Assembled by {@see \App\Service\Ai\EmbeddingBackfill} and read by both the
 * template and the JSON endpoint, so the card and the payload cannot disagree
 * about what is happening.
 *
 * THE ESTIMATE IS ALLOWED TO BE ABSENT
 * ────────────────────────────────────
 * `etaSeconds` is null far more often than it is set, and that is the feature.
 * An ETA computed from thirty seconds of a pass that yields to every click is
 * a number that swings between "four minutes" and "nine hours" while somebody
 * watches it, and a panel that does that is not trusted again. It appears only
 * once the measured rate has held still for long enough to mean something.
 */
final readonly class BackfillProgress
{
    public function __construct(
        public BackfillStatus       $status,
        public ?BackfillPauseReason $pauseReason,
        public ?string              $model,
        public int                  $embedded,
        public int                  $eligible,
        public int                  $failures,
        public ?float               $ratePerSecond,
        public ?int                 $etaSeconds,
        public ?DateTimeImmutable   $startedAt,
        public ?DateTimeImmutable   $lastProgressAt,
        public ?DateTimeImmutable   $finishedAt,
        /** Live in the queue, but nothing has moved for a long time. */
        public bool                 $stalled,
        public bool                 $canStart,
        public bool                 $canPause,
        public bool                 $canResume,
        /** Why a start would be refused, when it would be. */
        public ?string              $blockedReason,
        public int                  $batchSize,
        public int                  $pauseMs,
        public int                  $cooldownSeconds,
    ) {
    }

    /** How much of the mailbox is indexed, 0–100, never over. */
    public function percent(): float
    {
        if ($this->eligible <= 0) {
            return 0.0;
        }

        return round(min(100.0, $this->embedded / $this->eligible * 100), 1);
    }

    public function remaining(): int
    {
        return max(0, $this->eligible - $this->embedded);
    }

    /** Nothing has ever been embedded and nothing has ever been asked to be. */
    public function isUntouched(): bool
    {
        return 0 === $this->embedded && null === $this->startedAt;
    }

    /**
     * The panel's own shape, for the polled payload.
     *
     * Timestamps as ISO 8601 strings rather than objects: json_encode turns a
     * DateTimeImmutable into a three-key object with a timezone in it, which is
     * neither readable in a payload nor what a template's `|date` filter wants
     * back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status'          => $this->status->value,
            'pauseReason'     => $this->pauseReason?->value,
            'model'           => $this->model,
            'embedded'        => $this->embedded,
            'eligible'        => $this->eligible,
            'percent'         => $this->percent(),
            'remaining'       => $this->remaining(),
            'failures'        => $this->failures,
            'ratePerSecond'   => $this->ratePerSecond,
            'etaSeconds'      => $this->etaSeconds,
            'startedAt'       => $this->startedAt?->format(DateTimeImmutable::ATOM),
            'lastProgressAt'  => $this->lastProgressAt?->format(DateTimeImmutable::ATOM),
            'finishedAt'      => $this->finishedAt?->format(DateTimeImmutable::ATOM),
            'stalled'         => $this->stalled,
            'canStart'        => $this->canStart,
            'canPause'        => $this->canPause,
            'canResume'       => $this->canResume,
            'blockedReason'   => $this->blockedReason,
            'batchSize'       => $this->batchSize,
            'pauseMs'         => $this->pauseMs,
            'cooldownSeconds' => $this->cooldownSeconds,
        ];
    }
}
