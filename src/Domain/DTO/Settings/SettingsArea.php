<?php

declare(strict_types=1);

namespace App\Domain\DTO\Settings;

/**
 * One area of the settings tree — Account, General, Inbox and so on — and its
 * pages, in the order the tree lists them.
 */
final readonly class SettingsArea
{
    /**
     * @param list<SettingsPage> $pages
     */
    public function __construct(
        public string $key,
        /** Translation key of the area's name, e.g. 'settings.nav.area.inbox'. */
        public string $labelKey,
        /** FontAwesome classes, e.g. 'fa-solid fa-inbox'. */
        public string $icon,
        public array  $pages,
    ) {
    }
}
