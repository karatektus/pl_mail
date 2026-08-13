<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Entity\Embeddable\Appearance;

/**
 * `App\Entity\Embeddable\Appearance` → the JMAP Appearance object, and back
 * out again as the compact read the Session carries.
 *
 * Shared by Appearance/get, Appearance/set and SessionBuilder so the three
 * cannot disagree about what a property is called or which of them exist: a
 * client that reads `theme` from the Session and writes `theme` back through
 * /set is doing the only sane thing, and one spelling per property is what
 * makes it work.
 *
 * Deliberately NOT `Appearance::toArray()`, which is the *export* payload —
 * it drops the background file on purpose (a filename is not portable between
 * installs) and carries a `version` a JMAP object has no use for. Reusing it
 * would tie the wire format to the shape of a theme-export file, so the next
 * change to one would silently change the other.
 */
final class AppearanceMapper
{
    /**
     * The one id this object ever has (RFC 8620 §5.3's singleton pattern, as
     * used by RFC 8621's VacationResponse).
     */
    public const string SINGLETON_ID = 'singleton';

    /**
     * Every property of the JMAP object except `id`, in the order they are
     * emitted. `backgroundFile` is included and is read-only — see toJmap().
     *
     * @var list<string>
     */
    public const array PROPERTIES = [
        'theme',
        'layout',
        'accent',
        'paneAlpha',
        'paneBlur',
        'radius',
        'density',
        'backgroundKind',
        'backgroundPreset',
        'backgroundFile',
        'backgroundSolid',
        'scrimAlpha',
        'inkColor',
        'inkMuted',
        'inkFaint',
        'mainTint',
        'mainAlpha',
        'accountCorner',
        'listAvatars',
        'previewLines',
        'unreadEmphasis',
        'fontFamily',
        'fontScale',
        'sidebarDensity',
        'listDensity',
        'readingDensity',
    ];

    /**
     * The subset the Session publishes: enough to paint the chrome on the
     * first frame, without repeating the whole object in a response every
     * client fetches whether it cares about theming or not.
     *
     * @var list<string>
     */
    public const array COMPACT_PROPERTIES = ['theme', 'layout', 'accent', 'density'];

    /**
     * `backgroundFile` is reported and is not settable. It names a file
     * uploaded through the web settings pane, served from a route behind the
     * session firewall — a JMAP client can neither upload one nor fetch it, so
     * accepting the property would mean storing a filename that resolves to
     * nothing. It is still reported, because `backgroundKind: "custom"` with
     * no way to see what the custom background *is* leaves a client unable to
     * tell "the user chose a photo I cannot draw" from "the value is broken".
     *
     * @return array<string,mixed>
     */
    public function toJmap(Appearance $appearance): array
    {
        return [
            'id' => self::SINGLETON_ID,
            'theme' => $appearance->theme->value,
            'layout' => $appearance->layout->value,
            'accent' => $appearance->accent,
            'paneAlpha' => $appearance->paneAlpha,
            'paneBlur' => $appearance->paneBlur,
            'radius' => $appearance->radius,
            'density' => $appearance->density->value,
            'backgroundKind' => $appearance->backgroundKind->value,
            'backgroundPreset' => $appearance->backgroundPreset?->value,
            'backgroundFile' => $appearance->backgroundFile,
            'backgroundSolid' => $appearance->backgroundSolid,
            'scrimAlpha' => $appearance->scrimAlpha,
            'inkColor' => $appearance->inkColor,
            'inkMuted' => $appearance->inkMuted,
            'inkFaint' => $appearance->inkFaint,
            'mainTint' => $appearance->mainTint,
            'mainAlpha' => $appearance->mainAlpha,
            'accountCorner' => $appearance->accountCorner,
            'listAvatars' => $appearance->listAvatars,
            'previewLines' => $appearance->previewLines,
            'unreadEmphasis' => $appearance->unreadEmphasis->value,
            'fontFamily' => $appearance->fontFamily->value,
            'fontScale' => $appearance->fontScale,
            // Null is a value here and not an absence: it means the surface
            // follows the global `density`, which is the only way back from an
            // override. A client that sends null is asking for that.
            'sidebarDensity' => $appearance->sidebarDensity?->value,
            'listDensity' => $appearance->listDensity?->value,
            'readingDensity' => $appearance->readingDensity?->value,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function compact(Appearance $appearance): array
    {
        return array_intersect_key(
            $this->toJmap($appearance),
            array_flip(self::COMPACT_PROPERTIES),
        );
    }

    /**
     * The state token for the singleton, derived from its own values rather
     * than read from the change log.
     *
     * Appearance is not in the log and cannot be: the log is keyed by (mail
     * account, object type) and this belongs to the *user*, so there is no
     * account to file it under. Hashing the object gives a token with the one
     * property a client needs — it differs exactly when something differs —
     * without inventing a second sequence. It is not monotonic, so there is no
     * Appearance/changes and never will be; a client that finds the token
     * moved re-fetches the one object, which is a single call.
     */
    public function state(Appearance $appearance): string
    {
        $encoded = json_encode($this->toJmap($appearance), JSON_THROW_ON_ERROR);

        return substr(hash('xxh128', $encoded), 0, 16);
    }
}
