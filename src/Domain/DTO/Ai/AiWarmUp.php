<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * What happened when an administrator asked the host to load a model.
 *
 * AiProbe's shape, on purpose: the two answer the same kind of question about
 * the same machine, they are read by the same person on the same page, and one
 * of them already established that "could not connect" and "connected, and
 * refused" must not collapse into a single red cross. A second vocabulary for
 * the same distinction would be a second thing to keep in step.
 *
 * `reason` is a translation KEY plus parameters and never a sentence — the rule
 * AiProbe and AccountHealthInspector both follow, because nothing down here
 * knows what language anybody reads.
 *
 * WHY THE DURATION IS ON BOTH OUTCOMES
 * ────────────────────────────────────
 * It is the entire product of the button. "Loaded in 41 s" and "loaded in
 * 30 ms" are the same success and completely different facts: the first says
 * the model came off disk, the second that it was already resident and the
 * click bought nothing. A failure is worth timing for the mirror-image reason —
 * a refusal after four minutes is a host that tried and ran out of memory,
 * while the same refusal in 20 ms is a name typed wrong.
 */
final readonly class AiWarmUp
{
    /**
     * @param array<string, string|int> $reasonParams
     */
    public function __construct(
        public bool    $loaded,
        public int     $milliseconds,
        public ?string $reason = null,
        public array   $reasonParams = [],
    ) {
    }

    public static function loaded(int $milliseconds): self
    {
        return new self(true, $milliseconds);
    }

    /**
     * @param array<string, string|int> $params
     */
    public static function failed(string $reason, array $params = [], int $milliseconds = 0): self
    {
        return new self(false, $milliseconds, $reason, $params);
    }
}
