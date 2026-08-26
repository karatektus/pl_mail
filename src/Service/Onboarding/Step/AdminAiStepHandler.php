<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Form\Admin\AiSettingsType;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The optional one: whether this installation talks to a language model.
 *
 * It sits with the other two admin steps because it configures the install
 * rather than a person's mailbox, and it sits LAST of the three because it is
 * the only one an administrator can reasonably answer with "no" — mail
 * credentials and integrations decide whether the app works at all, and this
 * decides whether it does something extra.
 *
 * SATISFIED MEANS ANSWERED, NOT ENABLED
 * ─────────────────────────────────────
 * "No thank you" is a complete answer here and has to count as one, or the
 * wizard would nag forever at every install that does not want this — which
 * will be most of them. So the step is satisfied once somebody has either
 * switched it on with a host, or explicitly recorded that they looked: an
 * ai_settings row existing at all is that record, because the only way one
 * appears is somebody pressing the button on this page or in Admin → AI.
 *
 * That is deliberately weaker than the other admin steps. It has to be: the
 * strongest available signal of "decided against" is indistinguishable from
 * "never opened" unless we write something down when they say no.
 */
final readonly class AdminAiStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface   $formFactory,
        private UrlGeneratorInterface  $urlGenerator,
        private AiSettingsRepository   $settings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::AdminAi;
    }

    public function isApplicable(User $user): bool
    {
        // Never whether they have done it — see the interface. Only: may they.
        return true === in_array(User::ROLE_ADMIN, $user->getRoles(), true);
    }

    public function isSatisfied(User $user): bool
    {
        // A row exists only because somebody pressed the button on this page or
        // in Admin → AI, so its presence is the record that the question was
        // put and answered — including answered "no".
        return null !== $this->settings->current();
    }

    public function failureMessage(User $user): ?string
    {
        // Nothing here can be verified without reaching another machine, and a
        // wizard step that fails because a container is not up yet would block
        // setup on something that has nothing to do with setup. The Test button
        // in Admin → AI is where that question belongs.
        return null;
    }

    public function template(): string
    {
        return 'onboarding/steps/_admin_ai.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        return $this->formFactory->create(AiSettingsType::class, $this->settings->currentOrDefault(), [
            // Explicit, because a form rendered into a turbo-frame otherwise
            // posts to the document URL — which here is whatever page the
            // wizard was opened over.
            'action' => $this->urlGenerator->generate('app_onboarding_step', [
                'step' => $this->step()->value,
            ]),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        return [
            'settings'       => $this->settings->currentOrDefault(),
            'aside_template' => 'onboarding/steps/_admin_ai_aside.html.twig',
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $settings = $form->getData();

        // Unmapped, so an empty box leaves a stored token alone rather than
        // clearing it — the same rule the admin page follows.
        $token = (string) $form->get('apiToken')->getData();

        if ('' !== trim($token)) {
            $settings->apiToken = $token;
        }

        // Persisted even when every box is empty, and that is the point: the
        // row IS the record that somebody was asked and said no. Without it the
        // step would be unsatisfiable by anybody who does not want the feature.
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }
}
