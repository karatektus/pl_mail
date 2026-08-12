<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * An external service plMail connects to on a user's behalf — a file store, a
 * photo library, or a calendar server.
 *
 * It began as "a file or photo service plMail can attach from and save to", and
 * the docblock said so until CalDav arrived. What decides where a provider is
 * offered is kind(), not this sentence: a CalDAV server has nothing to attach
 * and must never appear in "Save to…", which is what ServiceKind exists to say.
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

    /**
     * Any RFC 4791 calendar server: Nextcloud, Radicale, Baïkal, Fastmail,
     * iCloud, a Synology box.
     *
     * The one case that is a protocol rather than a product, and named for the
     * protocol on purpose. There is no "Radicale" case and there will not be
     * one — the driver asks the server what it can do (supported-report-set,
     * current-user-privilege-set) instead of asking an enum which server this
     * is, so a CalDAV implementation nobody here has heard of works the day it
     * is pointed at.
     *
     * The value is lower-case 'caldav' rather than the camelCase the file
     * providers use, so slug() and transKey() answer 'caldav' — the spelling
     * everyone writing documentation, a template name or a support ticket will
     * reach for first.
     */
    case CalDav = 'caldav';

    /**
     * A published iCalendar file at an address — a holiday feed, a sports
     * fixture list, the "secret address in iCal format" Google and Outlook
     * offer for a calendar they will not let plMail write to.
     *
     * The second case that is a format rather than a product, and the only one
     * that is read-only by nature: the far end is a static file, so there is no
     * protocol to ask for permission with and nothing to push to. That is not a
     * limitation of the driver, it is what an ICS URL is, and
     * IcsUrlCalendarDriver hard-codes isReadOnly rather than probing for it.
     *
     * `webcal://` is the same thing under the scheme Apple registered for it;
     * IcsUrlNormaliser turns one into https before anything else sees it.
     */
    case Ics = 'ics';

    public function label(): string
    {
        return match ($this) {
            self::Nextcloud    => 'Nextcloud',
            self::Immich       => 'Immich',
            self::GoogleDrive  => 'Google Drive',
            self::GooglePhotos => 'Google Photos',
            self::OneDrive     => 'OneDrive',
            self::Dropbox      => 'Dropbox',
            self::CalDav       => 'CalDAV',
            self::Ics          => 'Calendar feed',
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
            // No brand mark exists for a protocol, so it takes the same
            // calendar glyph the calendar UI uses for a mirrored list.
            self::CalDav       => 'fa-solid fa-calendar-days',
            // Deliberately not the same glyph as CalDAV, although both are
            // calendars: the two sit in one list on the settings screen and a
            // shared mark would make "which of these can I edit?" unanswerable
            // at a glance. A feed is a subscription, so it takes the feed mark.
            self::Ics          => 'fa-solid fa-rss',
        };
    }

    /**
     * Which part of the application offers this service.
     *
     * Exhaustive, and every list that walks Provider::cases() filters on it.
     * Without that a calendar connection shows up in the file picker and in
     * "Save to…", which is how it would be found: by a user trying to attach
     * a photo from their CalDAV server.
     */
    public function kind(): ServiceKind
    {
        return match ($this) {
            self::Nextcloud,
            self::Immich,
            self::GoogleDrive,
            self::GooglePhotos,
            self::OneDrive,
            self::Dropbox => ServiceKind::Files,
            self::CalDav,
            self::Ics     => ServiceKind::Calendar,
        };
    }

    /**
     * Exhaustive, with no `default` arm — and that is the point rather than a
     * style preference. It used to fall through to OAuth2, so a provider added
     * later claimed an OAuth flow it had no endpoints for and failed at connect
     * time instead of at compile time.
     */
    public function authKind(): AuthKind
    {
        return match ($this) {
            self::Nextcloud,
            self::Immich,
            // Every CalDAV server worth connecting to takes an app-specific
            // password (iCloud and Fastmail require one; Nextcloud and Baïkal
            // offer one), and the protocol's own answer is HTTP Basic. OAuth
            // over CalDAV exists only at Google, and Google's calendars come
            // in through the mail grant instead.
            self::CalDav => AuthKind::AppPassword,
            // AppPassword although a feed asks for no password, and the reason
            // is what this enum actually decides: needsBaseUrl() is derived
            // from it, and the feed URL is the one thing a user must supply and
            // the one thing IntegrationUrlValidator must be given the chance to
            // refuse. A third AuthKind case saying "no credential" would be
            // honester by a word and would cost every form, every admin
            // template and every `settings.integrations.auth.*` key a branch
            // for a distinction only this line makes.
            //
            // For a published calendar the address IS the credential — Google
            // and Outlook both call theirs a secret address — which is why the
            // connect form warns that anyone holding it can read the calendar,
            // and why nothing renders it back once it is stored.
            self::Ics    => AuthKind::AppPassword,
            self::GoogleDrive,
            self::GooglePhotos,
            self::OneDrive,
            self::Dropbox => AuthKind::OAuth2,
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
     * Exhaustive on purpose. The `default` arm this used to carry handed the
     * full file capability set to any case that had not been thought about —
     * so a provider that could not upload at all still appeared in "Save to…",
     * and only said so once a user had picked it.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            // Immich's search is CLIP smart search over the whole library, and
            // it is the only provider that also summarises a timeline.
            self::Immich => [
                Capability::Browse,
                Capability::Download,
                Capability::Upload,
                Capability::Thumbnail,
                Capability::Search,
                Capability::Timeline,
            ],
            // Google Photos is the one without search: its Library API offers
            // no text search over media, only album and date filters.
            self::GooglePhotos => [
                Capability::Browse,
                Capability::Download,
                Capability::Upload,
                Capability::Thumbnail,
            ],
            self::Nextcloud,
            self::GoogleDrive,
            self::OneDrive,
            self::Dropbox => [
                Capability::Browse,
                Capability::Download,
                Capability::Upload,
                Capability::ShareLink,
                Capability::Thumbnail,
                Capability::Search,
            ],
            // Empty because a CalDAV connection holds no files, not because
            // nobody has filled it in. Capability is the file vocabulary
            // throughout — Browse, Download, Upload, ShareLink, Thumbnail — and
            // there is no honest answer to "can this be browsed in the picker"
            // other than no. What a calendar connection can do is asked of the
            // server itself, per collection, at sync time.
            self::CalDav,
            self::Ics    => [],
        };
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * The providers one part of the application should offer.
     *
     * Here rather than in each controller so the file lists and the calendar
     * lists cannot drift: adding a service means giving it a kind, and it
     * appears in exactly one of them.
     *
     * @return list<self>
     */
    public static function of(ServiceKind $kind): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $kind === $provider->kind(),
        ));
    }

    /**
     * Whether a driver exists. False renders the provider as a stub.
     *
     * Every case is listed rather than returning true outright: a provider
     * added later must default to "no driver" and show as a stub, not claim to
     * work and fail at the registry after the user has already authorised.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            // Self-hosted first, then the registered ones, which is also the
            // order an admin meets them in.
            self::Nextcloud,
            self::Immich,
            self::CalDav,
            self::Ics,
            self::GoogleDrive,
            self::GooglePhotos,
            self::OneDrive,
            self::Dropbox => true,
            default       => false,
        };
    }

    /**
     * OAuth scopes to request, empty for app-password providers.
     *
     * Google Drive asks for the full `drive` scope rather than `drive.file`.
     * drive.file only ever sees files the app itself created or the user picked
     * through Google's own client-side Picker, so a server-rendered browser
     * would show an empty Drive — and a share link needs write access to the
     * file being shared, which drive.file cannot grant for a file we did not
     * create. Both are restricted scopes requiring Google verification; the
     * tutorial says so.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::GoogleDrive => [
                'https://www.googleapis.com/auth/drive',
            ],
            self::GooglePhotos => [
                // readonly is what makes an existing library browsable; see
                // GooglePhotosDriver for why a new app may not be granted it.
                'https://www.googleapis.com/auth/photoslibrary.readonly',
                'https://www.googleapis.com/auth/photoslibrary.appendonly',
            ],
            self::OneDrive => [
                'Files.ReadWrite',
                'offline_access',
            ],
            self::Dropbox => [
                'files.metadata.read',
                'files.content.read',
                'files.content.write',
                'sharing.write',
            ],
            default => [],
        };
    }

    /**
     * Extra authorization-URL parameters, per provider quirk.
     *
     * @return array<string,string>
     */
    public function authorizationUrlOptions(): array
    {
        return match ($this) {
            // Without both of these Google issues a refresh token only on the
            // very first consent, so a reconnect leaves us unable to refresh.
            self::GoogleDrive, self::GooglePhotos => [
                'access_type' => 'offline',
                'prompt'      => 'consent',
            ],
            // Dropbox's equivalent: without it every token is short-lived and
            // there is no refresh token at all.
            self::Dropbox => [
                'token_access_type' => 'offline',
            ],
            default => [],
        };
    }

    /**
     * Authorize / token / resource-owner endpoints for providers built on
     * league's GenericProvider. Null where a dedicated provider class knows
     * its own endpoints (Google, Azure).
     *
     * @return array{urlAuthorize:string,urlAccessToken:string,urlResourceOwnerDetails:string}|null
     */
    public function oauthEndpoints(): ?array
    {
        return match ($this) {
            self::Dropbox => [
                'urlAuthorize'            => 'https://www.dropbox.com/oauth2/authorize',
                'urlAccessToken'          => 'https://api.dropboxapi.com/oauth2/token',
                'urlResourceOwnerDetails' => 'https://api.dropboxapi.com/2/users/get_current_account',
            ],
            default => null,
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
     * Whether this holds photos rather than files.
     *
     * Decides how the picker renders: a grid of previews for a photo library,
     * a list of names for a file store. The distinction is real rather than
     * cosmetic — nobody recognises a photo by reading "IMG_4821.jpg", and
     * nobody picks a spreadsheet out of a wall of thumbnails.
     */
    public function isMediaLibrary(): bool
    {
        return match ($this) {
            self::Immich, self::GooglePhotos => true,
            default                         => false,
        };
    }

    /**
     * Whether a "Save to…" of this content type could possibly land.
     *
     * A photo library (Immich, Google Photos) ingests images and videos and
     * nothing else — its API rejects a PDF outright — so offering "Save to
     * Immich" for a document is a button that is guaranteed to fail. Gating it
     * here, off the same media-library flag the picker already renders from,
     * keeps that decision with the taxonomy rather than in the template.
     *
     * A file store takes anything, so it answers true unconditionally,
     * including for a part whose type is unknown: a null or empty mime is
     * "we could not tell", and the safe reading of that is general storage,
     * never a photo library that would reject most of what "unknown" turns out
     * to be.
     */
    public function acceptsMime(?string $mime): bool
    {
        if (false === $this->isMediaLibrary()) {
            return true;
        }

        if (null === $mime || '' === $mime) {
            return false;
        }

        return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/');
    }

    /**
     * Translation key stem for this provider's UI copy, e.g.
     * "settings.integrations.provider.nextcloud.help".
     */
    public function transKey(): string
    {
        return $this->value;
    }

    /**
     * snake_case form, for filenames — the tutorial partials are included by
     * this rather than by a match arm, so adding a provider is a new template
     * and nothing else.
     */
    public function slug(): string
    {
        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $this->value));
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
