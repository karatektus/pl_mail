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
     * emitted. `backgroundFile` and `logoStyle` are included and are both
     * read-only — see toJmap().
     *
     * Being in this list is what makes a property *readable* by name: it is
     * what Appearance/get validates its `properties` argument against. It is
     * ALSO what Appearance/set's validate() accepts, so a read-only property
     * has to be named there too — the list on its own cannot express the
     * difference, and a property added here and nowhere else is silently
     * writable. Both of the read-only two are named in that method.
     *
     * @var list<string>
     */
    public const array PROPERTIES = [
        'theme',
        'layout',
        'logoStyle',
        'accent',
        'paneAlpha',
        'paneBlur',
        'radius',
        'density',
        'motion',
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
     * `logoStyle` is deliberately NOT here, and the reason is staleness rather
     * than size. The Session's `state` is a hash of the user's account ids, so
     * it does not move when an appearance changes: a client holding a cached
     * Session holds a hint that can be wrong for as long as it keeps that
     * Session, and only Appearance/get corrects it. That is a fair trade for
     * the four above, because being briefly wrong about them costs one frame
     * of the wrong palette — they are repainted the moment the real read
     * lands. It is not a fair trade for the mark: the client this was added
     * for uses it to choose a launcher icon, which is not a thing repainted on
     * the next frame but a thing committed to outside the app, where a wrong
     * value sits on somebody's home screen and a correction is visible churn.
     * A value you commit to should come from the authoritative read, and
     * Appearance/get is one call.
     *
     * The closed vocabulary it is drawn from IS published at discovery time —
     * see SessionBuilder's `logoStyles` — because that list is static and
     * cannot go stale. Knowing the alphabet early is the part with no
     * downside; knowing this user's letter early is the part with one.
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
     * `logoStyle` is reported and is not settable either, for a different
     * reason: it is not a stored field at all. What goes out is
     * {@see Appearance::effectiveLogoStyle()} — the colourway the mark ACTUALLY
     * WEARS — because the mark follows the theme by default (`logoLinked`, on
     * for everyone until they turn it off) and only speaks for the stored
     * `logoStyle` once it does not. Publishing the stored field instead would
     * be the more literal reading of the column name and would be wrong for
     * most users: somebody on the ocean theme sees an ocean mark in the topbar
     * and in the favicon, and a client told "berry" would draw a different
     * mark from the web for the same account, which is precisely the
     * disagreement this object exists to prevent. Every reader in the app goes
     * through effectiveLogoStyle() — the topbar, the favicon route — and the
     * wire is now one more reader.
     *
     * That derivation is also why it cannot be written. `logoStyle` on the
     * wire is a function of three stored things (`theme`, `logoLinked`,
     * `logoStyle`), so a client setting it would be asking the server to guess
     * which of the three it meant; and while the mark is linked, any answer it
     * gave would be overwritten by the theme on the next read. `logoLinked`
     * and the raw column are not on the wire at all, so there is nothing here
     * to write coherently — see AppearanceSetMethod, which refuses a change to
     * it and drops an echo of it.
     *
     * @return array<string,mixed>
     */
    public function toJmap(Appearance $appearance): array
    {
        return [
            'id' => self::SINGLETON_ID,
            'theme' => $appearance->theme->value,
            'layout' => $appearance->layout->value,
            'logoStyle' => $appearance->effectiveLogoStyle()->value,
            'accent' => $appearance->accent,
            'paneAlpha' => $appearance->paneAlpha,
            'paneBlur' => $appearance->paneBlur,
            'radius' => $appearance->radius,
            'density' => $appearance->density->value,
            'motion' => $appearance->motion->value,
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
