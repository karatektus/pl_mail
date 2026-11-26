<?php

declare(strict_types=1);

namespace App\Domain\Enum\User;

/**
 * Which number the browser tab icon carries (#662), for somebody who has the
 * count on it switched on in Settings → Notifications.
 *
 * NEW BY DEFAULT. The icon is looked at while the tab is not, to answer "has
 * anything arrived?", and that is the question the sidebar's "new" dots answer
 * (NewMailMarkers): conversations that arrived and were not put in front of
 * the reader yet. Unread answers a different one, "what have I not read?",
 * and a reader who leaves mail unread on purpose would see a number on the
 * icon that never goes down.
 *
 * Stored only when it is Unread: an absent key is New, so nobody's setting
 * has to be written for the default to hold.
 */
enum TabIconCountKind: string
{
    case New    = 'new';
    case Unread = 'unread';
}
