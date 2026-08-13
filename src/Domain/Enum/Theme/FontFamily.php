<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

/**
 * The typeface the INTERFACE is drawn in.
 *
 * Deliberately not the compose window's font picker, which is a different
 * feature that happens to share a word. That one writes `style="font-family:…"`
 * into the message HTML and travels to the recipient; this one never leaves the
 * chrome. They must not be conflated, and nothing here touches the editor —
 * app.css pins the editor's own family and size so a person composing sees what
 * they are sending rather than what they set here.
 *
 * Every stack is fonts the machine already has. The app ships no webfont and
 * cannot start: a self-hosted install behind a firewall must not depend on a
 * font CDN, and a 400KB download to change the shape of the sidebar is a poor
 * trade. So the choice is between families a desktop and a phone both have,
 * which is why the list is four and not forty.
 */
enum FontFamily: string
{
    case System   = 'system';
    case Grotesk  = 'grotesk';
    case Serif    = 'serif';
    case Monospace = 'monospace';

    /**
     * The CSS `font-family` value.
     *
     * System is first and is the default, because it is the one that looks
     * native on every machine — the others are a preference, not an upgrade.
     */
    public function stack(): string
    {
        return match ($this) {
            self::System => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            self::Grotesk => '"Helvetica Neue", Helvetica, "Segoe UI", Arial, sans-serif',
            self::Serif => 'Georgia, Cambria, "Times New Roman", Times, serif',
            self::Monospace => 'ui-monospace, "SF Mono", "Cascadia Mono", Menlo, Consolas, monospace',
        };
    }
}
