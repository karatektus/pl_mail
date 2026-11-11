<?php

declare(strict_types=1);

namespace App\Domain\DTO\Settings;

/**
 * One card on a settings page, as navigation and search know it: the anchor it
 * is drawn under, the heading it is drawn with, and the words it is found by.
 *
 * The card's markup does not come from here. Each page's template draws its own
 * cards, and this is the index of them; SettingsPageAnchorsTest renders every
 * page and fails when an anchor listed here is missing from it, which is the one
 * way the two can drift apart.
 *
 * $headingKey is the key the card's own header already uses, not a second label
 * for search. A result that read differently from the card it leads to would
 * look like it had opened the wrong page.
 */
final readonly class SettingsCard
{
    public function __construct(
        /** The element id on the page, e.g. 'card-timezone'. Becomes the URL fragment. */
        public string $anchor,
        /** Translation key of the card's heading, e.g. 'settings.timezone.heading'. */
        public string $headingKey,
        /**
         * Translation key of extra search words, or null where the heading says it
         * all. Space-separated words in the catalogue: what someone types who does
         * not know the heading — "dark" for Theme, "2fa" for two-factor.
         */
        public ?string $keywordsKey = null,
    ) {
    }
}
