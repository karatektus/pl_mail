<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Form\User\ProfileType;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use App\Entity\Integration\Integration;
use App\Service\User\AvatarFromIntegration;
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
        private AvatarFromIntegration $avatarSource,
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

    public function isSatisfied(User $user): bool
    {
        return '' !== trim((string) $user->getNameFirst())
            && '' !== trim((string) $user->getNameLast());
    }

    public function failureMessage(User $user): ?string
    {
        // Nothing here can be verified without the user making a round trip of
        // their own: credentials prove nothing until somebody consents with
        // them, and a name cannot be wrong.
        return null;
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
        $picking = $this->picking($user, $request);

        return $this->formFactory->create(ProfileType::class, $user, [
            'action'        => $this->stepUrl($picking?->id === null ? null : (string) $picking->id),
            'avatar_source' => null === $picking ? null : (string) $picking->id,
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        // Asked on every render, never cached: connecting a service happens one
        // step earlier in the wizard, so a user who just added Immich must find
        // it offered here without having to reload anything.
        $available = $this->avatarSource->availableFor($user);
        $picking   = $this->picking($user, $request);

        return [
            'aside_template' => 'onboarding/steps/_profile_aside.html.twig',
            'avatarSources'  => $available,
            'picking'        => $picking,
            'pickEntries'    => null === $picking ? [] : $this->avatarSource->browse($picking),
            'pickUrls'       => $this->pickUrls($available),
            'closeUrl'       => $this->stepUrl(),
        ];
    }

    /**
     * The connection whose pictures are on screen, if any.
     */
    private function picking(User $user, Request $request): ?Integration
    {
        $id = (string) $request->query->get('pick', '');

        if ('' === $id) {
            return null;
        }

        foreach ($this->avatarSource->availableFor($user) as $integration) {
            // Matched against the user's own connections rather than looked up
            // by id, so a borrowed id cannot browse somebody else's photos.
            if ((string) $integration->id === $id) {
                return $integration;
            }
        }

        return null;
    }

    /**
     * @param list<Integration> $integrations
     *
     * @return array<string, string>
     */
    private function pickUrls(array $integrations): array
    {
        $urls = [];

        foreach ($integrations as $integration) {
            $urls[(string) $integration->id] = $this->stepUrl((string) $integration->id);
        }

        return $urls;
    }

    private function stepUrl(?string $pick = null): string
    {
        return $this->urlGenerator->generate('app_onboarding_step', array_filter([
            'step' => $this->step()->value,
            'pick' => $pick,
        ]));
    }

    public function persist(User $user, FormInterface $form): void
    {
        $this->profileUpdater->apply($user, $form);
    }



}
