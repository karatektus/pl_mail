<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Domain\Enum\Onboarding\OnboardingStep;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Traversable;

/**
 * Finds the handler for a step.
 *
 * Indexed on first use rather than in the constructor: the iterator is lazy, so
 * building the map eagerly would instantiate every handler — and everything
 * they depend on — for any request that merely has the registry injected.
 */
final class OnboardingStepRegistry
{
    /** @var array<string, OnboardingStepHandlerInterface>|null */
    private ?array $indexed = null;

    /**
     * @param Traversable<OnboardingStepHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireIterator('app.onboarding_step')]
        private readonly Traversable $handlers,
    ) {
    }

    /**
     * Whether a step has been built yet.
     *
     * The wizard is assembled a step at a time, so during that work a case can
     * exist in the enum with no handler behind it. OnboardingFlow skips those
     * rather than exploding, and the controller's 404 covers anyone hand-typing
     * one. OnboardingStepCoverageTest is what stops that tolerance turning into
     * a step nobody notices is missing.
     */
    public function has(OnboardingStep $step): bool
    {
        return isset($this->index()[$step->value]);
    }

    public function handlerFor(OnboardingStep $step): OnboardingStepHandlerInterface
    {
        return $this->index()[$step->value]
            ?? throw new LogicException(sprintf(
                'No handler is registered for onboarding step "%s". Every case of %s needs one.',
                $step->value,
                OnboardingStep::class,
            ));
    }

    /**
     * @return array<string, OnboardingStepHandlerInterface>
     */
    private function index(): array
    {
        if (null !== $this->indexed) {
            return $this->indexed;
        }

        $indexed = [];

        foreach ($this->handlers as $handler) {
            $indexed[$handler->step()->value] = $handler;
        }

        return $this->indexed = $indexed;
    }
}
