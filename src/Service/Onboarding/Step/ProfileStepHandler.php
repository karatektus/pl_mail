<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Form\User\ProfileType;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use App\Service\User\ProfileUpdater;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Who the user is: the name that appears on the mail they send.
 *
 * Always applicable. A name that is already filled in is not a reason to skip
 * the step — unlike a mail account, there is no way to tell a real answer from
 * a placeholder, and being shown your own name to confirm costs one click.
 */
final readonly class ProfileStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private ProfileUpdater $profileUpdater,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Profile;
    }

    public function isApplicable(User $user): bool
    {
        return true;
    }

    public function template(): string
    {
        return 'onboarding/steps/_profile.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        // An explicit action, always: a form rendered into a turbo-frame posts
        // to the document URL otherwise, which here is whatever page the modal
        // was opened over.
        return $this->formFactory->create(ProfileType::class, $user, [
            'action' => $this->urlGenerator->generate('app_onboarding_step', ['step' => $this->step()->value]),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        return ['aside_template' => 'onboarding/steps/_profile_aside.html.twig'];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $this->profileUpdater->apply($user, $form);
    }
}
