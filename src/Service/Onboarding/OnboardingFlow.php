<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which steps a user gets, where they are in them, and whether they are done.
 *
 * The only place that reads or writes the onboarding keys in the user's
 * settings bag, and the only place that flushes them. Everything else — the
 * controller, the templates, the Twig global — asks this.
 *
 * Pending-ness is derived from a settings key rather than from `lastLogin`,
 * which the authenticator overwrites on the way in, and which would forget
 * itself the moment a step bounced the user through an OAuth consent screen.
 */
final readonly class OnboardingFlow
{
    public function __construct(
        private OnboardingStepRegistry $registry,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The steps this user is actually offered, in order.
     *
     * @return list<OnboardingStep>
     */
    public function applicableSteps(User $user): array
    {
        return array_values(array_filter(
            OnboardingStep::cases(),
            fn (OnboardingStep $step): bool => $this->isApplicable($user, $step),
        ));
    }

    public function isApplicable(User $user, OnboardingStep $step): bool
    {
        if (false === $this->registry->has($step)) {
            return false;
        }

        return $this->registry->handlerFor($step)->isApplicable($user);
    }

    /**
     * Where to open. The remembered step when there is one and it still
     * applies, otherwise the first — a resume point can go stale, e.g. an admin
     * step that another admin has since configured.
     */
    public function firstStep(User $user): ?OnboardingStep
    {
        $steps = $this->applicableSteps($user);

        if ([] === $steps) {
            return null;
        }

        $remembered = $this->rememberedStep($user);

        if (null !== $remembered && in_array($remembered, $steps, true)) {
            return $remembered;
        }

        return $steps[0];
    }

    /**
     * The next step to show after this one.
     *
     * Ordered by the enum rather than by position in the applicable list,
     * because the step being left may have dropped out of that list on its way
     * out: saving the last mail provider, or the only account, can be the very
     * thing that satisfies a step. Searching the list for a step no longer in
     * it used to fall back to its first entry, which sent the user backwards.
     */
    public function next(User $user, OnboardingStep $step): ?OnboardingStep
    {
        $applicable = $this->applicableSteps($user);
        $seen       = false;

        foreach (OnboardingStep::cases() as $candidate) {
            if ($candidate === $step) {
                $seen = true;

                continue;
            }

            if (true === $seen && in_array($candidate, $applicable, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The applicable step before this one, by enum order — see next(). */
    public function previous(User $user, OnboardingStep $step): ?OnboardingStep
    {
        $applicable = $this->applicableSteps($user);
        $previous   = null;

        foreach (OnboardingStep::cases() as $candidate) {
            if ($candidate === $step) {
                return $previous;
            }

            if (true === in_array($candidate, $applicable, true)) {
                $previous = $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{index: int, total: int}
     */
    public function progress(User $user, OnboardingStep $step): array
    {
        $steps    = $this->applicableSteps($user);
        $position = array_search($step, $steps, true);

        return [
            'index' => false === $position ? 1 : $position + 1,
            'total' => max(1, count($steps)),
        ];
    }

    public function isPending(User $user): bool
    {
        return null === $user->getSetting(User::SETTING_ONBOARDING_COMPLETED_AT);
    }

    /**
     * How each step stands, keyed by step value: answered, and if not working,
     * why.
     *
     * "Answered" is not "behind you": the progress rail marks these complete,
     * and a step the user skipped past must not come out looking done. Two
     * sources, because both mean the same thing to the person looking at it —
     * a step they submitted here, and a step the handler says is satisfied
     * anyway (an account exists, credentials are on file), which covers someone
     * who did it in Settings instead.
     *
     * @return array<string, array{done: bool, error: string|null}>
     */
    public function stepStatuses(User $user): array
    {
        $done     = $this->done($user);
        $statuses = [];

        foreach (OnboardingStep::cases() as $step) {
            $handler = $this->registry->has($step) ? $this->registry->handlerFor($step) : null;
            $error   = $handler?->failureMessage($user);

            $statuses[$step->value] = [
                // A step that is not working is not done, whatever was pressed
                // on the way past. Saying "done" to a mistyped password is
                // worse than saying nothing, because the user stops looking.
                'done' => null === $error && (
                    in_array($step->value, $done, true)
                    || true === $handler?->isSatisfied($user)
                ),
                'error' => $error,
            ];
        }

        return $statuses;
    }

    public function markStepDone(User $user, OnboardingStep $step): void
    {
        $done = $this->done($user);

        if (false === in_array($step->value, $done, true)) {
            $done[] = $step->value;
        }

        $user->setSetting(User::SETTING_ONBOARDING_DONE_STEPS, $done);

        $this->entityManager->flush();
    }

    public function isSkipped(User $user, OnboardingStep $step): bool
    {
        return in_array($step->value, $this->skipped($user), true);
    }

    /** Remember where to come back to, e.g. before leaving for an OAuth consent screen. */
    public function rememberStep(User $user, OnboardingStep $step): void
    {
        $user->setSetting(User::SETTING_ONBOARDING_STEP, $step->value);

        $this->entityManager->flush();
    }

    public function markSkipped(User $user, OnboardingStep $step): void
    {
        $skipped = $this->skipped($user);

        if (false === in_array($step->value, $skipped, true)) {
            $skipped[] = $step->value;
        }

        $user->setSetting(User::SETTING_ONBOARDING_SKIPPED, $skipped);

        $this->entityManager->flush();
    }

    /**
     * Finished, or dismissed — the wizard does not distinguish, because from
     * here on the difference is only whether the user reopens it themselves.
     */
    public function markComplete(User $user): void
    {
        $user
            ->setSetting(User::SETTING_ONBOARDING_COMPLETED_AT, (new DateTimeImmutable())->format(DateTimeInterface::ATOM))
            ->setSetting(User::SETTING_ONBOARDING_STEP, null);

        $this->entityManager->flush();
    }

    /** Start again from the top, keeping nothing from the last run. */
    public function reset(User $user): void
    {
        $user
            ->setSetting(User::SETTING_ONBOARDING_COMPLETED_AT, null)
            ->setSetting(User::SETTING_ONBOARDING_STEP, null)
            ->setSetting(User::SETTING_ONBOARDING_SKIPPED, [])
            ->setSetting(User::SETTING_ONBOARDING_DONE_STEPS, []);

        $this->entityManager->flush();
    }

    private function rememberedStep(User $user): ?OnboardingStep
    {
        $value = $user->getSetting(User::SETTING_ONBOARDING_STEP);

        return is_string($value) ? OnboardingStep::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    private function done(User $user): array
    {
        $done = $user->getSetting(User::SETTING_ONBOARDING_DONE_STEPS, []);

        if (false === is_array($done)) {
            return [];
        }

        return array_values(array_filter($done, 'is_string'));
    }

    /**
     * @return list<string>
     */
    private function skipped(User $user): array
    {
        $skipped = $user->getSetting(User::SETTING_ONBOARDING_SKIPPED, []);

        if (false === is_array($skipped)) {
            return [];
        }

        return array_values(array_filter($skipped, 'is_string'));
    }
}
