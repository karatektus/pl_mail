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

    public function next(User $user, OnboardingStep $step): ?OnboardingStep
    {
        $steps    = $this->applicableSteps($user);
        $position = array_search($step, $steps, true);

        if (false === $position) {
            return $steps[0] ?? null;
        }

        return $steps[$position + 1] ?? null;
    }

    public function previous(User $user, OnboardingStep $step): ?OnboardingStep
    {
        $steps    = $this->applicableSteps($user);
        $position = array_search($step, $steps, true);

        if (false === $position || 0 === $position) {
            return null;
        }

        return $steps[$position - 1] ?? null;
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
            ->setSetting(User::SETTING_ONBOARDING_SKIPPED, []);

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
    private function skipped(User $user): array
    {
        $skipped = $user->getSetting(User::SETTING_ONBOARDING_SKIPPED, []);

        if (false === is_array($skipped)) {
            return [];
        }

        return array_values(array_filter($skipped, 'is_string'));
    }
}
