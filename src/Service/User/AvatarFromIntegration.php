<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Domain\DTO\Integration\Entry;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AvatarStorage;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Taking a profile picture from a service the user has already connected.
 *
 * The photos are usually already there — in Immich, in Google Photos — and
 * asking someone to download one and upload it again is asking them to do the
 * computer's job.
 *
 * Deliberately not the compose file picker: that one is built around attaching
 * to a draft, and its browse endpoint takes a draft id. What is needed here is
 * narrower — one image, no folders to speak of, no selection modes — so it
 * borrows the drivers rather than the picker.
 */
final readonly class AvatarFromIntegration
{
    /** Enough to choose from without turning the step into a gallery. */
    private const int LIMIT = 24;

    public function __construct(
        private IntegrationRepository $integrations,
        private IntegrationDriverRegistry $drivers,
        private AvatarStorage $avatars,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Connections this user could pick a picture from, right now.
     *
     * Checked on every render rather than cached: connecting a service is a
     * step or two earlier in the wizard, so the answer changes while the user
     * is sitting in it.
     *
     * @return list<Integration>
     */
    public function availableFor(User $user): array
    {
        return array_values(array_filter(
            $this->integrations->findBy(['usr' => $user, 'isActive' => true]),
            // Thumbnail as well as Browse and Download: without previews the
            // grid is a list of filenames, which is not a way to choose a
            // photograph.
            static fn (Integration $integration): bool => $integration->supports(Capability::Browse)
                && $integration->supports(Capability::Download)
                && $integration->supports(Capability::Thumbnail),
        ));
    }

    /**
     * Images to choose from.
     *
     * Failure comes back as an empty list rather than an exception: a service
     * that is down should leave the rest of the step usable.
     *
     * @return list<Entry>
     */
    public function browse(Integration $integration): array
    {
        try {
            $listing = $this->drivers->forIntegration($integration)->list($integration);
        } catch (IntegrationException) {
            return [];
        }

        $images = array_values(array_filter($listing->files(), self::looksLikeAnImage(...)));

        return array_slice($images, 0, self::LIMIT);
    }

    /**
     * Not every driver reports a mime type — a photo library that only ever
     * returns photographs has little reason to — so the filename is the
     * fallback rather than the entry being dropped.
     */
    private static function looksLikeAnImage(Entry $entry): bool
    {
        if (null !== $entry->mime && '' !== $entry->mime) {
            return str_starts_with($entry->mime, 'image/');
        }

        return in_array(
            strtolower(pathinfo($entry->name, PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'heif'],
            true,
        );
    }

    /**
     * Fetch the chosen file and make it the user's avatar.
     *
     * @throws RuntimeException when the file is not usable as a picture
     */
    public function apply(User $user, Integration $integration, string $fileId): void
    {
        $file = $this->drivers->forIntegration($integration)->download($integration, $fileId);

        if (false === str_starts_with($file->mime, 'image/')) {
            throw new RuntimeException('The chosen file is not an image.');
        }

        if ($file->size() > AvatarStorage::MAX_BYTES) {
            throw new RuntimeException('The chosen image is too large.');
        }

        $userId = (string) $user->getId();

        $user->setAvatar($this->avatars->storeContents($userId, $file->filename, $file->contents));

        $this->entityManager->flush();
    }
}
