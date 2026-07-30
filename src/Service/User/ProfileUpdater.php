<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Domain\Helper\AvatarStorage;
use App\Service\User\AvatarFromIntegration;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Applying a submitted profile form.
 *
 * The names map themselves; the avatar does not — the form carries a file and
 * the entity carries a filename. Shared by the setup wizard and the settings
 * page, so an avatar uploaded in one is the avatar shown by the other.
 */
final readonly class ProfileUpdater
{
    public function __construct(
        private AvatarStorage $avatars,
        private AvatarFromIntegration $fromIntegration,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(User $user, FormInterface $form): void
    {
        $userId = (string) $user->getId();

        // A picture chosen from a connected service, if a thumbnail was the
        // thing that submitted the form. Before the upload, so picking one and
        // choosing a file in the same submission leaves the file — the same
        // order as removal below.
        $this->applyPicked($user, $form);

        // Removal first: ticking the box and picking a file in the same
        // submission should leave the new file, not delete it.
        if (true === $form->has('removeAvatar') && true === $form->get('removeAvatar')->getData()) {
            $this->avatars->deleteAllFor($userId);
            $user->setAvatar(null);
        }

        $file = true === $form->has('avatarFile') ? $form->get('avatarFile')->getData() : null;

        if ($file instanceof UploadedFile) {
            $user->setAvatar($this->avatars->store($userId, $file));
        }

        $this->entityManager->flush();
    }

    private function applyPicked(User $user, FormInterface $form): void
    {
        if (false === $form->has('avatarFileId')) {
            return;
        }

        $fileId = trim((string) $form->get('avatarFileId')->getData());
        $source = trim((string) $form->get('avatarIntegrationId')->getData());

        if ('' === $fileId || '' === $source) {
            return;
        }

        foreach ($this->fromIntegration->availableFor($user) as $integration) {
            // Resolved against the user's own connections, never by trusting
            // the id that came back in the form.
            if ((string) $integration->id === $source) {
                $this->fromIntegration->apply($user, $integration, $fileId);

                return;
            }
        }
    }
}
