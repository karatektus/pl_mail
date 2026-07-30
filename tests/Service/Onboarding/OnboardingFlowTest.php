<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Service\Onboarding\OnboardingFlow;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use App\Service\Onboarding\OnboardingStepRegistry;
use ArrayIterator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which steps a user is offered, and where they are in them.
 *
 * No database and no kernel: everything here is a function of the user's
 * settings bag and what the handlers say, so the test can build both by hand.
 * The cases that matter are the ones where the list is *not* every step — an
 * admin stop for a plain user, or a step for something already configured — and
 * the navigation around it, because "next" over a filtered list is where an
 * off-by-one hides.
 */
final class OnboardingFlowTest extends TestCase
{
    public function testAUserOnlyGetsTheStepsThatApplyToThem(): void
    {
        $flow = $this->flow([
            [OnboardingStep::AdminMailCredentials, false],
            [OnboardingStep::Account, true],
            [OnboardingStep::Profile, true],
        ]);

        self::assertSame(
            [OnboardingStep::Account, OnboardingStep::Profile],
            $flow->applicableSteps(new User()),
        );
    }

    public function testAStepWithNoHandlerIsNotOffered(): void
    {
        // The wizard is built a step at a time; a case with nothing behind it
        // yet must not appear, and must not throw.
        $flow = $this->flow([[OnboardingStep::Profile, true]]);

        self::assertSame([OnboardingStep::Profile], $flow->applicableSteps(new User()));
        self::assertFalse($flow->isApplicable(new User(), OnboardingStep::Account));
    }

    public function testNextAndPreviousWalkTheFilteredList(): void
    {
        // Appearance is missing in the middle: next() from Account must reach
        // Profile, not fall into the gap.
        $flow = $this->flow([
            [OnboardingStep::Account, true],
            [OnboardingStep::Profile, true],
            [OnboardingStep::Appearance, false],
            [OnboardingStep::Integrations, true],
        ]);

        $user = new User();

        self::assertSame(OnboardingStep::Profile, $flow->next($user, OnboardingStep::Account));
        self::assertSame(OnboardingStep::Integrations, $flow->next($user, OnboardingStep::Profile));
        self::assertSame(OnboardingStep::Profile, $flow->previous($user, OnboardingStep::Integrations));
    }

    public function testTheEndsOfTheListHaveNowhereToGo(): void
    {
        $flow = $this->flow([[OnboardingStep::Account, true], [OnboardingStep::Profile, true]]);
        $user = new User();

        self::assertNull($flow->previous($user, OnboardingStep::Account), 'the first step has no back');
        self::assertNull($flow->next($user, OnboardingStep::Profile), 'the last step finishes instead');
    }

    public function testProgressCountsOnlyTheStepsOnScreen(): void
    {
        $flow = $this->flow([
            [OnboardingStep::AdminMailCredentials, false],
            [OnboardingStep::Account, true],
            [OnboardingStep::Profile, true],
        ]);

        self::assertSame(['index' => 2, 'total' => 2], $flow->progress(new User(), OnboardingStep::Profile));
    }

    public function testAUserWhoHasNeverSeenTheWizardIsPending(): void
    {
        $flow = $this->flow([[OnboardingStep::Profile, true]]);
        $user = new User();

        // Absent means pending, which is what makes existing users work without
        // anything having to backfill their settings.
        self::assertTrue($flow->isPending($user));

        $flow->markComplete($user);

        self::assertFalse($flow->isPending($user));
    }

    public function testResetPutsAFinishedUserBackToTheStart(): void
    {
        $flow = $this->flow([[OnboardingStep::Profile, true]]);
        $user = new User();

        $flow->markSkipped($user, OnboardingStep::Profile);
        $flow->markComplete($user);

        $flow->reset($user);

        self::assertTrue($flow->isPending($user));
        self::assertFalse($flow->isSkipped($user, OnboardingStep::Profile));
    }

    public function testItOpensAtTheRememberedStepWhenThatStepStillApplies(): void
    {
        $flow = $this->flow([[OnboardingStep::Account, true], [OnboardingStep::Profile, true]]);
        $user = new User();

        $flow->rememberStep($user, OnboardingStep::Profile);

        self::assertSame(OnboardingStep::Profile, $flow->firstStep($user), 'an OAuth round trip must come back where it left off');
    }

    public function testAStaleResumePointFallsBackToTheStart(): void
    {
        // Remembered while it applied; by the time the user came back, another
        // admin had configured it away.
        $flow = $this->flow([[OnboardingStep::Account, true], [OnboardingStep::Profile, true]]);
        $user = new User();
        $flow->rememberStep($user, OnboardingStep::Profile);

        $narrowed = $this->flow([[OnboardingStep::Account, true], [OnboardingStep::Profile, false]]);

        self::assertSame(OnboardingStep::Account, $narrowed->firstStep($user));
    }

    public function testSkippingIsRemembered(): void
    {
        $flow = $this->flow([[OnboardingStep::Account, true], [OnboardingStep::Profile, true]]);
        $user = new User();

        $flow->markSkipped($user, OnboardingStep::Account);
        $flow->markSkipped($user, OnboardingStep::Account);

        self::assertTrue($flow->isSkipped($user, OnboardingStep::Account));
        self::assertFalse($flow->isSkipped($user, OnboardingStep::Profile));
    }

    /**
     * @param list<array{OnboardingStep, bool}> $applicability
     */
    private function flow(array $applicability): OnboardingFlow
    {
        $handlers = [];

        foreach ($applicability as [$step, $isApplicable]) {
            $handlers[] = $this->handler($step, $isApplicable);
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);

        return new OnboardingFlow(new OnboardingStepRegistry(new ArrayIterator($handlers)), $entityManager);
    }

    private function handler(OnboardingStep $step, bool $isApplicable): OnboardingStepHandlerInterface
    {
        return new class($step, $isApplicable) implements OnboardingStepHandlerInterface {
            public function __construct(
                private readonly OnboardingStep $step,
                private readonly bool $isApplicable,
            ) {
            }

            public function step(): OnboardingStep
            {
                return $this->step;
            }

            public function isApplicable(User $user): bool
            {
                return $this->isApplicable;
            }

            public function template(): string
            {
                return 'onboarding/steps/_profile.html.twig';
            }

            public function createForm(User $user, Request $request): FormInterface
            {
                throw new \LogicException('not needed here');
            }

            public function viewData(User $user, Request $request): array
            {
                return [];
            }

            public function persist(User $user, FormInterface $form): void
            {
            }
        };
    }
}
