<?php

declare(strict_types=1);

namespace App\Domain\DTO\Appearance;

/**
 * Somebody's theme, reduced to the three strings a page needs to wear it.
 *
 * The public pages — a shared calendar, a booking page — belong to a user the
 * reader has no account with and is not entitled to learn anything about. They
 * should still look like that user's plMail rather than like a default nobody
 * chose, which is the whole of what this object carries: a class list, a theme
 * name, and a semicolon-separated block of CSS variables.
 *
 * **The template never receives the User.** That is the same argument
 * SharedOccurrence makes for its own fields, arrived at from the appearance
 * side: handed the owner, a public template could print their name, their
 * address or their id from any partial that grew a debug line. Handed three
 * strings it cannot, because the concrete data is not in the object the
 * renderer can reach.
 *
 * Nothing in $cssVariables identifies anybody either. PublicAppearanceResolver
 * builds it without a user id, which is what makes AppearanceRenderer drop a
 * custom uploaded background — that would be a URL carrying the owner's id
 * behind the firewall, so it would 404 here anyway and would say who they are
 * on the way.
 */
final readonly class PublicAppearance
{
    public function __construct(
        /** What goes on <html class="…"> — `dark` and the layout class, or empty. */
        public string $htmlClass,
        /** The `data-theme` value, one of Theme's cases. */
        public string $theme,
        /** Inline `style` for <html>: accent, ink ramp, radius, density, background. */
        public string $cssVariables,
    ) {
    }
}
