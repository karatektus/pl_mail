<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\User\TabIconCountKind;
use App\Entity\User\User;
use App\Service\Layout\WorkspaceScope;

/**
 * The number on plMail's icon in the browser tab (#662), for somebody who
 * switched it on in Settings → Notifications. Rendered with the page
 * (`<meta name="tab-icon-count">`) and sent with every counts answer under
 * KEY, so assets/tab_icon_count.js only ever draws a number and never has to
 * know what it means.
 *
 * Three choices make it, each stored on the user:
 *
 *   - what is counted (User::$tabIconCountKind): new conversations, the
 *     sidebar's dots (NewMailMarkers), or unread ones, its badges
 *     (SidebarCounts); new by default, see TabIconCountKind;
 *   - which account group's Inbox (User::$tabIconCountInbox), or every group
 *     together, the default;
 *   - whether only the workspace on screen counts
 *     (User::$tabIconCountThisWorkspace), or every workspace, the default.
 *
 * Its own number rather than the title's `role:inbox`, because the title
 * follows the workspace on screen (#783) and the icon by default does not: a
 * pinned tab is looked at to learn whether mail is waiting anywhere.
 *
 * Every group together is the union of their accounts, not the sum of their
 * counts: an account may be in several groups (#783), and its mail is one
 * reader's mail once.
 */
final readonly class TabIconCount
{
    /** The counts key, beside `role:inbox` and the rest in /mail/sidebar/counts. */
    public const string KEY = 'tab-icon';

    public function __construct(
        private SidebarCounts  $counts,
        private NewMailMarkers $newMail,
        private UnifiedInboxes $inboxes,
        private WorkspaceScope $workspaceScope,
    ) {
    }

    /** The number, or null when the user has the icon plain. */
    public function for(User $user): ?int
    {
        if (false === $user->tabIconCount) {
            return null;
        }

        $accountIds = $this->accountIds($user);

        // A group or a workspace with no accounts left in it counts nothing,
        // which is what it holds.
        if ([] === $accountIds) {
            return 0;
        }

        return TabIconCountKind::Unread === $user->tabIconCountKind
            ? $this->counts->unreadInInboxOf($accountIds)
            : $this->newMail->newInInboxOf($accountIds);
    }

    /**
     * The accounts counted: the chosen group's, or every one; then, when the
     * user asked for it, only those the workspace on screen shows.
     *
     * @return list<int>|null null for every account
     */
    private function accountIds(User $user): ?array
    {
        $group = null === $user->tabIconCountInbox ? null : $this->inboxes->find($user, $user->tabIconCountInbox);
        $ids   = null === $group ? null : $this->inboxes->accountIdsOf($user, $group);

        return true === $user->tabIconCountThisWorkspace ? $this->workspaceScope->narrow($user, $ids) : $ids;
    }
}
