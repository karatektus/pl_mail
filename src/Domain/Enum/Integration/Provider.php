<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * A file or photo service plMail can attach from and save to.
 *
 * Every case is listed in both the admin and the user UI, implemented or not.
 * isImplemented() returning false renders the provider greyed with its setup
 * tutorial still readable, so the four unfinished ones stay visible instead of
 * living in a backlog — flipping one on means writing its driver and returning
 * true here, and nothing else in the UI has to change.
 *
 * The folder carries the context, so this is Integration\Provider rather than
 * IntegrationProvider. Alias it on import where it would collide with
 * Account\MailProvider.
 */
enum Provider: string
{
    case Nextcloud = 'nextcloud';
    case Immich = 'immich';
    case GoogleDrive = 'googleDrive';
    case GooglePhotos = 'googlePhotos';
    case OneDrive = 'oneDrive';
    case Dropbox = 'dropbox';

    public function label(): string
    {
        return match ($this) {
            self::Nextcloud    => 'Nextcloud',
            self::Immich       => 'Immich',
            self::GoogleDrive  => 'Google Drive',
            self::GooglePhotos => 'Google Photos',
            self::OneDrive     => 'OneDrive',
            self::Dropbox      => 'Dropbox',
        };
    }

    /** FontAwesome class for the provider's mark. */
    public function icon(): string
    {
        return match ($this) {
            self::Nextcloud    => 'fa-solid fa-cloud',
            self::Immich       => 'fa-solid fa-images',
            self::GoogleDrive  => 'fa-brands fa-google-drive',
            self::GooglePhotos => 'fa-solid fa-photo-film',
            self::OneDrive     => 'fa-brands fa-microsoft',
            self::Dropbox      => 'fa-brands fa-dropbox',
        };
    }

    public function authKind(): AuthKind
    {
        return match ($this) {
            self::Nextcloud, self::Immich => AuthKind::AppPassword,
            default                       => AuthKind::OAuth2,
        };
    }

    /**
     * Declared for every provider, including the unimplemented ones, so the
     * admin tutorial can state honestly what connecting will buy.
     *
     * Immich and Google Photos are absent from ShareLink: neither exposes a
     * per-asset public URL without creating a shared album, which is a heavier
     * side effect than attaching a file should have.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Immich, self::GooglePhotos => [
                Capability::Browse,
                Capability::Download,
                Capability::Upload,
                Capability::Thumbnail,
            ],
            default => [
                Capability::Browse,
                Capability::Download,
                Capability::Upload,
                Capability::ShareLink,
                Capability::Thumbnail,
            ],
        };
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** Whether a driver exists. False renders the provider as a stub. */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::Nextcloud, self::Immich => true,
            default                       => false,
        };
    }

    /**
     * Whether the user has to supply the server address. Self-hosted services
     * have no canonical host; the SaaS ones do and must never be pointed
     * somewhere else.
     */
    public function needsBaseUrl(): bool
    {
        return AuthKind::AppPassword === $this->authKind();
    }

    /**
     * Translation key stem for this provider's UI copy, e.g.
     * "settings.integrations.provider.nextcloud.help".
     */
    public function transKey(): string
    {
        return $this->value;
    }

    /** @return list<self> */
    public static function implemented(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->isImplemented(),
        ));
    }
}
