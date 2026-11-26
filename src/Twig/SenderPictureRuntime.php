<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\SenderPictureView;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Entity\User\User;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Service\Mail\SenderPicture\SenderPictures;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The reading half of SenderPictureExtension (#639).
 */
final readonly class SenderPictureRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private SenderPictures                  $pictures,
        private SenderPictureSettingsRepository $settings,
        private Security                        $security,
    ) {}

    /** See SenderPictures::for(). */
    public function picture(?string $address, ?bool $verified = false): ?SenderPictureView
    {
        return $this->pictures->for($address, true === $verified);
    }

    /**
     * The signed-in user's switches, kind value => on, for the settings card.
     *
     * @return array<string, bool>
     */
    public function choices(): array
    {
        $user    = $this->security->getUser();
        $choices = [];

        foreach (SenderPictureKind::lookups() as $kind) {
            $choices[$kind->value] = $user instanceof User ? $user->allowsSenderPicture($kind) : $kind->onByDefault();
        }

        return $choices;
    }

    public function lookupsEnabled(): bool
    {
        return $this->settings->lookupsEnabled();
    }

    /** Whether the administrator runs the Libravatar route (#642); the profile's opt-in shows only then. */
    public function libravatarServing(): bool
    {
        return $this->settings->libravatarEnabled();
    }
}
