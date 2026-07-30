<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Form\User\ProfileType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Settings → Profile section's template parameters.
 *
 * Shared between the settings page that shows the section and the save
 * endpoint that re-renders it on a validation failure. The partial needs the
 * avatar picker's variables in both places — the save endpoint rendering it
 * with only the form is how picking a picture used to crash with
 * "Variable avatarSources does not exist".
 */
final readonly class ProfileSectionViewData
{
    public function __construct(
        private AvatarFromIntegration $avatarSources,
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param FormInterface|null $form a submitted form to render with its
     *                                 errors, instead of building a fresh one
     *
     * @return array<string, mixed>
     */
    public function build(User $user, Request $request, ?FormInterface $form = null): array
    {
        $sources = $this->avatarSources->availableFor($user);
        $picking = $this->picking($sources, $request);

        $pickUrls = [];

        foreach ($sources as $source) {
            $pickUrls[(string) $source->id] = $this->sectionUrl((string) $source->id);
        }

        $form ??= $this->formFactory->create(ProfileType::class, $user, [
            'action'        => $this->urlGenerator->generate('app_settings_profile_save'),
            'avatar_source' => null === $picking ? null : (string) $picking->id,
        ]);

        return [
            'profileForm'    => $form->createView(),
            'avatarSources'  => $sources,
            'avatarPicking'  => $picking,
            'avatarEntries'  => null === $picking ? [] : $this->avatarSources->browse($picking),
            'avatarPickUrls' => $pickUrls,
            'avatarCloseUrl' => $this->sectionUrl(),
        ];
    }

    /**
     * The connection whose pictures are on screen, if any.
     *
     * @param list<Integration> $sources
     */
    private function picking(array $sources, Request $request): ?Integration
    {
        foreach ($sources as $source) {
            // Matched against the user's own connections rather than looked up
            // by id, so a borrowed id cannot browse somebody else's photos.
            if ((string) $source->id === (string) $request->query->get('pick', '')) {
                return $source;
            }
        }

        return null;
    }

    private function sectionUrl(?string $pick = null): string
    {
        return $this->urlGenerator->generate('app_settings_index', array_filter([
            'section' => 'profile',
            'pick'    => $pick,
        ]));
    }
}
