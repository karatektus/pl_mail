<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\DTO\Appearance\PublicAppearance;
use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;

/**
 * The theme a page with no signed-in reader is drawn in: its owner's.
 *
 * AppearanceExtension answers the same question for the authenticated app, and
 * it answers it from `Security::getUser()` — which on a shared calendar or a
 * booking page is nobody, so every public page rendered through it would be the
 * unstyled `:root` defaults. That is not a theme anybody picked; it is the
 * fallback that exists so a stylesheet loaded before a user is known has
 * something to resolve against.
 *
 * So the owner is passed in explicitly. A shared link and a booking page each
 * hang off exactly one user, the server already has that row in hand to resolve
 * the link at all, and reading three fields off an embeddable costs nothing —
 * there is no second query here.
 *
 * ── Paper is the fallback, and it is a real answer ────────────────────────
 *
 * A null owner cannot happen through the two controllers that call this (both
 * 404 before they get here), but the signature allows it rather than throwing,
 * because the honest answer exists: `new Appearance()`, which is Paper with its
 * clay accent — what a fresh account starts as, by the argument on
 * Appearance::$theme. A page that renders in the product's default look is
 * right; a page that renders in no look at all is the complaint this whole
 * change came from.
 *
 * ── The user id is deliberately not passed on ─────────────────────────────
 *
 * AppearanceRenderer::cssVariables() takes one so it can point --app-bg at an
 * uploaded background, and that URL lives behind the firewall — it would 404
 * for a visitor with no account, and it would spell out the owner's id in the
 * markup on the way. Passing null makes the renderer fall through to the
 * theme's own background, which is the same thing the logged-out replay in
 * _layout/app.html.twig does with the same reasoning.
 */
final readonly class PublicAppearanceResolver
{
    public function __construct(private AppearanceRenderer $renderer)
    {
    }

    public function forOwner(?User $owner): PublicAppearance
    {
        // Spelled out rather than `$owner?->appearance ?? …`: `??` swallows the
        // null-property read, so the nullsafe operator in that expression is
        // dead and phpstan says so. The branch is the honest shape anyway —
        // "no owner" is a case with an answer, not a fallback.
        $appearance = null === $owner ? new Appearance() : $owner->appearance;

        return new PublicAppearance(
            $this->renderer->htmlClass($appearance),
            $appearance->theme->value,
            $this->renderer->cssVariables($appearance),
        );
    }
}
