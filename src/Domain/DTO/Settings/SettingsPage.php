<?php

declare(strict_types=1);

namespace App\Domain\DTO\Settings;

/**
 * One settings page: what /settings/{slug} renders, and the cards on it.
 *
 * $slug is the address and the name the template branches on, so it never
 * changes when a page moves to another area — the area is navigation, not part
 * of the URL. See SettingsNavigation on why the path carries no area segment.
 */
final readonly class SettingsPage
{
    /**
     * @param list<SettingsCard> $cards
     */
    public function __construct(
        public string $slug,
        public string $areaKey,
        /** Translation key of the page's name in the tree, e.g. 'settings.nav.region'. */
        public string $labelKey,
        public array  $cards,
    ) {
    }
}
