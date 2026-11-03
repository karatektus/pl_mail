<?php

declare(strict_types=1);

namespace App\Domain\DTO\Push;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\MessageCategory;

/**
 * The keys a notification choice is made in: one inbox tab, or one label.
 *
 * Strings rather than objects, because the strings ARE the contract. They are
 * stored in the settings bag (User::$notifyScopes), sent over JMAP
 * (NotificationSettings/get) and compared by the Android app, which coined
 * them: `category:<built-in>`, `tab:<custom tab key>`, `label:<label id>`. A
 * key this server writes has to be byte-for-byte what the phone writes for the
 * same switch, or the two would show the same choice as two different ones.
 *
 * A built-in tab is a `category:` key and never a `tab:` one, so `tab:primary`
 * does not exist; ofTab() is the one place that decides which prefix a tab key
 * gets. A label is keyed by the user-level label id (Mailbox.labelId on the
 * wire), not by a per-account binding, so one switch covers the label in
 * every account that has it.
 */
final class NotifyScope
{
    public const string CATEGORY_PREFIX = 'category:';
    public const string TAB_PREFIX      = 'tab:';
    public const string LABEL_PREFIX    = 'label:';

    /** The one scope that notifies until somebody chooses otherwise. */
    public const string PRIMARY = self::CATEGORY_PREFIX . 'primary';

    private function __construct()
    {
    }

    public static function category(MessageCategory $category): string
    {
        return self::CATEGORY_PREFIX . $category->value;
    }

    /**
     * The scope of an inbox tab, by the key the tab strip and the URL use
     * (InboxTab::$value): a built-in name, or `c` and a category id.
     */
    public static function ofTab(string $tabKey): string
    {
        return null !== MessageCategory::tryFrom($tabKey)
            ? self::CATEGORY_PREFIX . $tabKey
            : self::TAB_PREFIX . $tabKey;
    }

    public static function label(int $labelId): string
    {
        return self::LABEL_PREFIX . $labelId;
    }

    /**
     * Whether a key has a shape this server could ever have written.
     *
     * Shape only, not existence: a key for a tab or label that has since been
     * deleted is well-formed and harmless — nothing raises it any more — and
     * dropping it would make a choice made on the phone disappear the moment
     * the web saved anything. What this keeps out is text that could never be
     * a scope, so the settings bag does not become a place a client can store
     * whatever it likes.
     */
    public static function isWellFormed(string $key): bool
    {
        if (true === str_starts_with($key, self::CATEGORY_PREFIX)) {
            return null !== MessageCategory::tryFrom(substr($key, strlen(self::CATEGORY_PREFIX)));
        }

        if (true === str_starts_with($key, self::TAB_PREFIX)) {
            return true === InboxTab::looksCustom(substr($key, strlen(self::TAB_PREFIX)));
        }

        if (true === str_starts_with($key, self::LABEL_PREFIX)) {
            return 1 === preg_match('/^[1-9][0-9]*$/', substr($key, strlen(self::LABEL_PREFIX)));
        }

        return false;
    }
}
