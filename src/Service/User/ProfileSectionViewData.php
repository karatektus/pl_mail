<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;
use App\Form\User\ProfileType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Settings → Profile section's template parameters.
 *
 * Shared between the settings page that shows the section and the save
 * endpoint that re-renders it on a validation failure. The partial needs the
 * avatar picker's variables in both places — the save endpoint rendering it
 * with only the form is how picking a picture used to crash with
 * "Variable avatarSources does not exist".
 *
 * The picture is chosen in the file picker's dialog, so what is passed here is
 * only which connections to offer. It used to carry the chosen connection's
 * first page of images as well; that grid is gone from Settings.
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
    public function build(User $user, ?FormInterface $form = null): array
    {
        $form ??= $this->formFactory->create(ProfileType::class, $user, [
            'action' => $this->urlGenerator->generate('app_settings_profile_save'),
        ]);

        return [
            'profileForm'   => $form->createView(),
            // Which connections to offer; the pictures themselves are browsed in
            // the file picker's dialog, not rendered here.
            'avatarSources' => $this->avatarSources->availableFor($user),
        ];
    }
}
