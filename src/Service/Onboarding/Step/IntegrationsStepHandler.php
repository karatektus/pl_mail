<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The user's own file and photo services.
 *
 * Offered only when an admin has actually registered something connectable —
 * a step listing nothing is worse than no step.
 *
 * Links out to the existing connect flow rather than embedding it. Half of
 * these providers are OAuth and leave for a consent screen the moment they are
 * clicked, so there is no version of this that stays inside the dialog; and
 * connecting is not something to finish setup over. Where the user is comes
 * back from the settings bag on the next page load.
 */
final readonly class IntegrationsStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private IntegrationProviderConfigRepository $configs,
        private IntegrationRepository $integrations,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Integrations;
    }

    public function isApplicable(User $user): bool
    {
        return [] !== $this->configs->findConnectable();
    }

    public function template(): string
    {
        return 'onboarding/steps/_integrations.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        return $this->formFactory->create(FormType::class, null, [
            'action' => $this->urlGenerator->generate('app_onboarding_step', ['step' => $this->step()->value]),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        $connected = [];

        foreach ($this->integrations->findBy(['usr' => $user]) as $integration) {
            $connected[$integration->provider->value] = true;
        }

        return [
            'connectable' => $this->configs->findConnectable(),
            'connected'   => $connected,
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        // Nothing of its own: connecting happens through the settings flow this
        // step links to.
    }
}
