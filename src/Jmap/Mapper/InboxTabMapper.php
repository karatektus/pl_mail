<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;

/**
 * InboxTab objects, and the tab a conversation is shown in.
 *
 * An InboxTab is:
 *
 *   id         the tab key — a built-in category's value ("primary", "social",
 *              …) or one of the reader's own ("c12"). The same string the web
 *              puts in `?tab=`, Thread.inboxTab carries, Email/query's
 *              threadInboxTab takes and Thread/set accepts.
 *   name       the reader's name for a tab of their own; null for a built-in
 *              one, which a client names in its own language — "Primary" is
 *              not something a server should translate for a phone.
 *   builtIn    the category a built-in tab is, or null for one of the
 *              reader's own. Separate from `id` so a client never has to know
 *              which strings are built-ins.
 *   isEnabled  false for a built-in the reader switched off. Listed anyway,
 *              so a settings screen can switch it back on; a client drawing
 *              the strip leaves it out. Always true for the reader's own.
 *   sortOrder  the position in the strip, Primary at 0. Unique per user.
 *
 * Thread.inboxTab is the tab the web shows a conversation in: the reader's
 * own when one of their filters filed it there, else the built-in category —
 * and Primary when that category is switched off, so a phone draws the same
 * strip the browser does. Null when the conversation was never categorised,
 * as Thread.category is.
 */
final class InboxTabMapper
{
    /**
     * @return array{id: string, name: ?string, builtIn: ?string, isEnabled: bool, sortOrder: int}
     */
    public function toJmap(InboxTab $tab, int $sortOrder, User $user): array
    {
        return [
            'id'        => $tab->value,
            'name'      => $tab->isCustom() ? $tab->name : null,
            'builtIn'   => $tab->builtIn?->value,
            'isEnabled' => null === $tab->builtIn || false === $user->categorySorting->isSwitchedOff($tab->builtIn),
            'sortOrder' => $sortOrder,
        ];
    }

    public function tabOf(MessageThread $thread, User $user): ?string
    {
        if (null !== $thread->userCategory) {
            return $thread->userCategory->tabKey();
        }

        if (null === $thread->category) {
            return null;
        }

        return true === $user->categorySorting->isSwitchedOff($thread->category)
            ? MessageCategory::Primary->value
            : $thread->category->value;
    }
}
