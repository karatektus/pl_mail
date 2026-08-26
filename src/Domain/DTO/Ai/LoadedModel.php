<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use DateTimeImmutable;

/**
 * One model an Ollama host currently has in memory, as `/api/ps` reports it.
 *
 * Distinct from OllamaModel, which is one model the host has on DISK. The
 * difference is the whole point: a host can hold fifty models and have none of
 * them loaded, and the next request then pays a cold load — measured at around
 * thirteen seconds for a 20 GiB model on the target hardware. A panel that
 * only listed what was installed could not tell anybody that.
 *
 * WHY size_vram IS THE HEALTH INDICATOR
 * ─────────────────────────────────────
 * `size` is what the model needs; `size_vram` is how much of it the GPU
 * actually took. Equal means it is entirely on the GPU. Less means layers
 * spilled to the CPU, and throughput does not degrade gracefully when that
 * happens — it collapses. That state is invisible from everywhere else: the
 * model answers, the answers are correct, and everything is simply slow
 * forever. Catching it is most of why this exists.
 */
final readonly class LoadedModel
{
    public function __construct(
        public string             $name,
        public ?int               $sizeBytes = null,
        public ?int               $sizeVramBytes = null,
        public ?int               $contextLength = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string            $parameterSize = null,
        public ?string            $quantisation = null,
    ) {
    }

    /**
     * Is the whole model on the GPU?
     *
     * Unknown counts as yes. A host that does not report the two sizes is not
     * evidence of a problem, and painting a warning over missing data would
     * teach an operator to ignore the one place this is ever shown.
     */
    public function fullyOnGpu(): bool
    {
        if (null === $this->sizeBytes || null === $this->sizeVramBytes) {
            return true;
        }

        return $this->sizeVramBytes >= $this->sizeBytes;
    }

    /** How much of the model the GPU took, 0.0–1.0, or null when unknown. */
    public function gpuFraction(): ?float
    {
        if (null === $this->sizeBytes || null === $this->sizeVramBytes || $this->sizeBytes <= 0) {
            return null;
        }

        return min(1.0, $this->sizeVramBytes / $this->sizeBytes);
    }

    /**
     * Seconds until the host unloads this, or null when it does not say.
     *
     * This is the countdown to the next cold load, which is the number an
     * operator actually wants: "the model goes in four minutes, and getting it
     * back costs thirteen seconds".
     *
     * Never negative — an expiry in the past means it is going right now, and
     * a panel counting backwards through zero reads as broken.
     */
    public function secondsUntilUnload(DateTimeImmutable $now): ?int
    {
        if (null === $this->expiresAt) {
            return null;
        }

        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }
}
