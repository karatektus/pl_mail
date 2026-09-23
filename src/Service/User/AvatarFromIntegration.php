<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Domain\DTO\Integration\Entry;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\AvatarRefusedException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AvatarStorage;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use finfo;

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
     * Only what the avatar store will take — PNG, JPEG, GIF, WebP — so nothing
     * is offered that is then refused. A HEIC photograph is an image, but not
     * one this can keep, and offering it was offering an error.
     *
     * Not every driver reports a mime type — a photo library that only ever
     * returns photographs has little reason to — so the filename is the
     * fallback rather than the entry being dropped. Either is only a guess
     * about what the file will be; apply() decides on the bytes.
     */
    private static function looksLikeAnImage(Entry $entry): bool
    {
        if (null !== $entry->mime && '' !== $entry->mime) {
            return in_array(strtolower($entry->mime), AvatarStorage::ALLOWED_MIME, true);
        }

        return in_array(
            strtolower(pathinfo($entry->name, PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg', 'gif', 'webp'],
            true,
        );
    }

    /**
     * Fetch the chosen file and make it the user's avatar.
     *
     * **What it is comes from its bytes, not from the service.** A download is
     * often labelled application/octet-stream whatever it holds — the listing
     * that offered it already had to go by its name — and trusting the label
     * refused real photographs with "not an image", while trusting it the other
     * way would store whatever a service chose to call a picture. The upload
     * path asks the same question of an UploadedFile, which also sniffs.
     *
     * The stored name takes its extension from what was found, for the same
     * reason: it is what the avatar is served as.
     *
     * @throws AvatarRefusedException when the file cannot be fetched or kept
     */
    public function apply(User $user, Integration $integration, string $fileId): void
    {
        try {
            $file = $this->drivers->forIntegration($integration)->download($integration, $fileId);
        } catch (IntegrationException $e) {
            throw new AvatarRefusedException(AvatarRefusedException::UNAVAILABLE, $e);
        }

        if ($file->size() > AvatarStorage::MAX_BYTES) {
            throw new AvatarRefusedException(AvatarRefusedException::TOO_LARGE);
        }

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($file->contents);

        if (false === in_array($mime, AvatarStorage::ALLOWED_MIME, true)) {
            throw new AvatarRefusedException(AvatarRefusedException::NOT_AN_IMAGE);
        }

        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];

        $userId = (string) $user->id;
        $user->avatar = $this->avatars->storeContents($userId, 'avatar.'.$extension, $file->contents);
        $this->entityManager->flush();
    }
}
