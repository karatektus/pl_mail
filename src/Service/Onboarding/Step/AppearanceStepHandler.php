<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * How plMail looks.
 *
 * The one step with nothing to submit: the appearance controls save themselves
 * over fetch as they are clicked, so by the time Next is pressed the choice is
 * already stored. The form exists only to give Next something to submit — see
 * the note on OnboardingStepHandlerInterface::createForm().
 *
 * Always applicable. There is no such thing as an appearance that is already
 * configured, and the defaults are a starting point rather than an answer.
 */
final readonly class AppearanceStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Appearance;
    }

    public function isApplicable(User $user): bool
    {
        return true;
    }

    public function template(): string
    {
        return 'onboarding/steps/_appearance.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        return $this->formFactory->create(FormType::class, null, [
            'action' => $this->urlGenerator->generate('app_onboarding_step', ['step' => $this->step()->value]),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        return [];
    }

    public function persist(User $user, FormInterface $form): void
    {
        // Nothing to do: the appearance controls have already saved themselves.
    }
}
