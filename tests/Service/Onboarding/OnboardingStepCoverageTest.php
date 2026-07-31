<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding;

use App\Controller\Onboarding\OnboardingController;
use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Service\Onboarding\OnboardingStepRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every step in the enum has a handler behind it.
 *
 * OnboardingStepRegistry tolerates a case with nothing registered for it, which
 * is what let the wizard be built one step at a time. That tolerance is only
 * safe with this test next to it: without it, a step whose handler was renamed,
 * moved out of the autoconfigured namespace, or never written would simply stop
 * appearing, and nothing would say so.
 */
final class OnboardingStepCoverageTest extends KernelTestCase
{
    public function testEveryStepHasAHandler(): void
    {
        self::bootKernel();

        $registry = static::getContainer()->get(OnboardingStepRegistry::class);

        $missing = array_values(array_filter(
            OnboardingStep::cases(),
            static fn (OnboardingStep $step): bool => false === $registry->has($step),
        ));

        self::assertSame(
            [],
            array_map(static fn (OnboardingStep $step): string => $step->value, $missing),
            'every OnboardingStep needs a handler implementing OnboardingStepHandlerInterface',
        );
    }

    /**
     * Every step is also reachable.
     *
     * The /{step} route pins its values in a regex, because a route requirement
     * is an attribute argument and cannot be derived from the enum. A case
     * missing from it does not merely 404 — the progress rail generates a URL
     * for every applicable step, so one omission takes down every page of the
     * wizard, including the steps that were working.
     */
    public function testEveryStepIsAllowedByTheRouteRequirement(): void
    {
        $allowed = explode('|', OnboardingController::STEP_PATTERN);

        $missing = array_values(array_filter(
            OnboardingStep::cases(),
            static fn (OnboardingStep $step): bool => false === in_array($step->value, $allowed, true),
        ));

        self::assertSame(
            [],
            array_map(static fn (OnboardingStep $step): string => $step->value, $missing),
            'every OnboardingStep must appear in OnboardingController::STEP_PATTERN',
        );
    }
}
