<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The Gmail inbox category ("tab"). Stored per-message (raw signal) and
 * resolved most-recent-wins onto the thread. This is the full Gmail set —
 * how these fold into the UI tab bar is a separate view-layer concern
 * (see MessageThreadRepository / the inbox template), so the folding can
 * change without a migration.
 */
enum MessageCategory: string
{
    case Primary = 'primary';       // CATEGORY_PERSONAL (or none)
    case Social = 'social';         // CATEGORY_SOCIAL
    case Promotions = 'promotions'; // CATEGORY_PROMOTIONS
    case Updates = 'updates';       // CATEGORY_UPDATES
    case Forums = 'forums';         // CATEGORY_FORUMS

    /**
     * Resolve the category from raw Gmail label IDs. Gmail assigns exactly
     * one CATEGORY_* label to inbox mail; uncategorised mail is Personal.
     *
     * @param list<string> $labelIds
     */
    public static function fromGmailLabels(array $labelIds): self
    {
        return match (true) {
            in_array('CATEGORY_SOCIAL', $labelIds, true) => self::Social,
            in_array('CATEGORY_PROMOTIONS', $labelIds, true) => self::Promotions,
            in_array('CATEGORY_UPDATES', $labelIds, true) => self::Updates,
            in_array('CATEGORY_FORUMS', $labelIds, true) => self::Forums,
            default => self::Primary,
        };
    }
}
