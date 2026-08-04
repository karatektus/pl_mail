<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something that can read a date out of prose.
 *
 * There is exactly one implementation — DeterministicDateDetector — and the
 * interface exists anyway, for a reason that is worth stating plainly because
 * an interface with one implementation usually should not exist.
 *
 * The stage after this one is a model. Explicit and semi-explicit forms are the
 * cheap majority and they are arithmetic; the tail ("können wir uns nach den
 * Feiertagen zusammensetzen, sagen wir Mitte Januar?") is not parseable by any
 * amount of regex and is exactly what a language model is for. That detector
 * will be a class implementing this interface, tagged app.proposal_detector,
 * with a lower priority so it is asked only about what the deterministic one
 * could not read. Nothing else changes: not EventProposer, not the entity, not
 * the card, not the noise rules — which is the point, because the noise rules
 * are the part that must not be re-decided by whoever adds the model.
 *
 * NO model code, config or dependency exists yet, and none should be added
 * here. This is the seam, not the feature.
 *
 * Implementations are auto-tagged and run in priority order; the first one to
 * return a date wins. They must not decide whether the date may be offered —
 * see DetectedDate.
 */
#[AutoconfigureTag('app.proposal_detector')]
interface ProposalDetectorInterface
{
    /**
     * Stored on the proposal, so "which detector made this?" is answerable
     * after a second one exists. Short and stable: renaming it orphans the
     * rows already written.
     */
    public function name(): string;

    /** Higher runs first. Deterministic reading beats inference. */
    public function priority(): int;

    /**
     * @return DetectedDate|null null is the normal outcome and never an error:
     *                           most mail names no appointment
     */
    public function detect(ProposalContext $context): ?DetectedDate;
}
