<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageThreadRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-request cache of sidebar counts and the user label tree.
 *
 * Labels are user-scoped, so the tree is read straight from the repository.
 * This class used to merge same-named labels across accounts into a
 * LabelTreeNode per path — reconstructing at render time the unified label the
 * data model did not have. LabelBinding made that entity real, so the merge,
 * the node DTO and the id-summing counters are gone.
 */
class SidebarCounts implements ResetInterface
{
    private ?array $roleCounts = null;
    /** @var array<string,int> role value => total, filled on demand */
    private array $roleTotals = [];
    private ?array $labelCounts = null;
    private ?int $starredCount = null;
    private ?int $snoozedCount = null;
    private ?int $labelsUnread = null;
    private ?array $userLabelTree = null;
    private ?array $visibleLabels = null;
    /** @var array<int, list<Label>> account id => its visible labels */
    private array $accountLabels = [];
    /** @var array<int, array<int,int>> account id => (label id => unread) */
    private array $accountLabelCounts = [];

    /**
     * Worker-mode hygiene - see LogAlertGlobal::reset(), the sibling whose
     * staleness was actually caught in the wild.
     */
    public function reset(): void
    {
        $this->roleCounts         = null;
        $this->roleTotals         = [];
        $this->labelCounts        = null;
        $this->starredCount       = null;
        $this->snoozedCount       = null;
        $this->labelsUnread       = null;
        $this->userLabelTree      = null;
        $this->visibleLabels      = null;
        $this->accountLabels      = [];
        $this->accountLabelCounts = [];
    }

    public function __construct(
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly Security                $security,
    ) {}

    /**
     * The visible labels materialised on one account, for the folder list the
     * sidebar renders inline when that account is the expanded one.
     *
     * The same read MailController::accountFolders does, which still serves
     * the list when another account is expanded by hand. This exists so the
     * account that was *already* expanded is in the first paint instead of
     * being fetched after it — which is the difference between a sidebar and a
     * sidebar that blinks on every navigation.
     *
     * @return list<Label>
     */
    public function labelsForAccount(Account $account): array
    {
        return $this->accountLabels[(int) $account->id] ??= array_values(array_filter(
            $this->labelRepository->findBoundToAccount($account),
            static fn (Label $label): bool => true === $label->isVisible,
        ));
    }

    /**
     * The unread count for a label *within one account*, for the folder list
     * under that account.
     *
     * forLabel() answers across every account, which is right for the sidebar's
     * own label section — there a label means "everywhere" — and wrong under an
     * account, where clicking the row lists that account's threads alone and
     * the badge promised more than the list then showed.
     */
    public function forLabelInAccount(Label $label, Account $account): int
    {
        $accountId = (int) $account->id;

        if (false === array_key_exists($accountId, $this->accountLabelCounts)) {
            $user = $this->security->getUser();

            $this->accountLabelCounts[$accountId] = null === $user
                ? []
                : $this->threadRepository->countUnreadPerUserLabel($user, $account);
        }

        return $this->accountLabelCounts[$accountId][(int) $label->id] ?? 0;
    }

    /**
     * The roles whose badge counts everything in them rather than the unread
     * part of it.
     *
     * The badges used to mean "unread" everywhere, which reads wrong in exactly
     * two places. A bin and a drafts folder are not things you work through —
     * nobody triages Trash — so the unread number there answers a question
     * nobody asked, while looking identical to the one on Inbox that means
     * "these want you". It also made the Trash badge disagree with the list
     * under it: 188 unread against a list that said 193, two different true
     * answers to what looked like one question.
     *
     * So: everywhere else the badge is unread and wears the accent; here it is
     * the total and is styled neutrally, so the shape of it says which kind of
     * number it is before the number is read. The total is taken from the same
     * countForRole() the list header paginates with, so the two now agree by
     * construction rather than by coincidence.
     *
     * @var list<LabelRole>
     */
    public const array TOTAL_ROLES = [LabelRole::Trash, LabelRole::Drafts];

    public static function countsTotal(LabelRole $role): bool
    {
        return in_array($role, self::TOTAL_ROLES, true);
    }

    /**
     * The roles that carry no badge at all.
     *
     * Sent, and the reason is arithmetic rather than taste. countUnreadPerRole()
     * counts a THREAD towards every role the thread carries, and a thread's
     * labels are the union of its messages' (see ThreadLabelSynchronizer) — so
     * the moment you answer a conversation, the thread gains Sent while the
     * unread message in it is still the incoming one, sitting in the Inbox.
     * "Gesendet 1" was counting a mail that is not in Sent and never will be.
     * Reported as exactly that: mark one inbox thread unread, and Sent grows a
     * badge.
     *
     * Sent is the only role this can happen to. Trash and Drafts are totals
     * (below) and so are not summed this way; Archive and Spam are MOVES — the
     * message leaves Inbox to get there, so no thread holds both.
     *
     * Fixed by dropping the badge rather than by re-attributing the count,
     * because there is no number that would be right. Counting unread MESSAGES
     * carrying the Sent label would be worse, not better: nothing marks an
     * outgoing message as seen (MessageSendService sets sentAt, never seenAt),
     * so every mail you have ever sent would count as unread. And the honest
     * answer is that an unread count on Sent asks a question nobody has —
     * you have read everything you wrote, by construction. Same reasoning as
     * TOTAL_ROLES, one step further: there the badge means something else, here
     * it means nothing.
     *
     * @var list<LabelRole>
     */
    public const array SILENT_ROLES = [LabelRole::Sent];

    public static function badges(LabelRole $role): bool
    {
        return false === in_array($role, self::SILENT_ROLES, true);
    }

    /**
     * The number the badge for a role should show, whichever kind it is.
     *
     * The one place that decides, so the sidebar, the counts endpoint and the
     * browser title cannot drift apart — which is how the tab came to be stuck
     * at (4) while the sidebar said 3.
     */
    public function forRoleBadge(LabelRole $role): int
    {
        if (false === self::badges($role)) {
            return 0;
        }

        return true === self::countsTotal($role)
            ? $this->totalForRole($role)
            : $this->forRole($role);
    }

    /**
     * Everything filed under a role, unread or not — the list header's number.
     */
    public function totalForRole(LabelRole $role): int
    {
        $key = $role->value;

        if (false === array_key_exists($key, $this->roleTotals)) {
            $user = $this->security->getUser();

            $this->roleTotals[$key] = null === $user
                ? 0
                : $this->threadRepository->countForRole($user, $role);
        }

        return $this->roleTotals[$key];
    }

    public function forRole(LabelRole $role): int
    {
        if (null === $this->roleCounts) {
            $user = $this->security->getUser();

            if (null === $user) {
                $this->roleCounts = [];
            } else {
                $this->roleCounts = $this->threadRepository->countUnreadPerRole($user);
            }
        }

        return $this->roleCounts[$role->value] ?? 0;
    }

    public function forLabel(Label $label): int
    {
        $this->loadLabelCounts();

        return $this->labelCounts[(int) $label->id] ?? 0;
    }

    /**
     * Whether the user has this sidebar section or label tree collapsed.
     *
     * Here rather than reached through `app.user` in the template because the
     * label tree is rendered by a Twig MACRO, and a macro sees only globals —
     * which is already why the counts arrive this way. It also makes the
     * anonymous case a plain `false` instead of a null dereference.
     */
    public function isCollapsed(string $key): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $user->isSidebarSectionCollapsed($key);
    }

    /**
     * The LABELS heading's own number, shown only while that section is
     * collapsed — see MessageThreadRepository::countUnreadInUserLabels() for
     * why it is not the sum of the rows it stands in for.
     */
    public function unreadInLabels(): int
    {
        if (null === $this->labelsUnread) {
            $user = $this->security->getUser();

            $this->labelsUnread = null === $user
                ? 0
                : $this->threadRepository->countUnreadInUserLabels($user);
        }

        return $this->labelsUnread;
    }

    public function forStarred(): int
    {
        if (null === $this->starredCount) {
            $user = $this->security->getUser();

            if (null === $user) {
                $this->starredCount = 0;
            } else {
                $this->starredCount = $this->threadRepository->countUnreadForStarred($user);
            }
        }

        return $this->starredCount;
    }

    /**
     * Visible custom labels as a root-level list; children hang off
     * Label::$children and the template recurses.
     *
     * @return Label[]
     */
    public function userLabelTree(): array
    {
        if (null === $this->userLabelTree) {
            $roots = [];

            foreach ($this->getVisibleLabels() as $label) {
                if (true === $label->isSystem || null !== $label->parent) {
                    continue;
                }

                $roots[] = $label;
            }

            usort($roots, function (Label $a, Label $b): int {
                return mb_strtolower((string) $a->name) <=> mb_strtolower((string) $b->name);
            });

            $this->userLabelTree = $roots;
        }

        return $this->userLabelTree;
    }

    /**
     * Visible children of a label, alphabetically — the template's recursion
     * step. Keeps the "hidden labels stay hidden" rule in one place.
     *
     * @return Label[]
     */
    public function visibleChildrenOf(Label $label): array
    {
        $children = [];

        // Read out of the set findVisibleForUser() already returned rather
        // than through $label->children, which is a lazy collection and
        // therefore one `SELECT … FROM label WHERE parent_id = ?` per label in
        // the tree, every page load. Not the fifty-per-page N+1 the list rows
        // had — it is per LABEL, so it grows with how organised the user is
        // rather than with how much mail they have — but it is the same shape,
        // and the answer is already in memory: the visible labels are
        // user-scoped and all of them were loaded up front. A child that is
        // not in that set is invisible, which is exactly what the filter used
        // to drop.
        foreach ($this->getVisibleLabels() as $child) {
            if ($child->parent === $label) {
                $children[] = $child;
            }
        }

        usort($children, function (Label $a, Label $b): int {
            return mb_strtolower((string) $a->name) <=> mb_strtolower((string) $b->name);
        });

        return $children;
    }

    /**
     * True when anything is currently snoozed — controls the Snoozed entry in
     * the system nav block.
     *
     * Gated rather than always shown, on the same reasoning as Archive: the
     * label is created lazily the first time something is snoozed, so on an
     * install that has never used the feature there is nothing to link to.
     */
    public function hasSnoozed(): bool
    {
        if (null === $this->snoozedCount) {
            $user = $this->security->getUser();

            $this->snoozedCount = null === $user
                ? 0
                : $this->threadRepository->countSnoozedForUser($user);
        }

        return $this->snoozedCount > 0;
    }

    /**
     * True when the user's Archive label is switched visible — controls the
     * Archive entry in the system nav block.
     */
    public function hasVisibleArchive(): bool
    {
        return $this->hasVisibleRole(LabelRole::Archive);
    }

    /**
     * Whether a system label is switched visible in the label settings.
     *
     * Generalised from hasVisibleArchive() because Spam needed the same
     * question asked, and the answer was that nothing asked it: the eye toggle
     * in label settings happily switched Spam on — the toggle route allows
     * system labels precisely so it can — and no sidebar entry was ever
     * looking, because the system nav is a hand-written sequence of anchors
     * rather than a loop and had no Spam arm at all. A user could turn the
     * setting on and off all day and nothing would appear.
     */
    public function hasVisibleRole(LabelRole $role): bool
    {
        foreach ($this->getVisibleLabels() as $label) {
            if ($role === $label->role) {
                return true;
            }
        }

        return false;
    }

    /**
     * Depth-first flattening of the visible custom label tree, for the
     * "Label as" dropdown. Indentation in the template derives from
     * label.depth.
     *
     * @return Label[]
     */
    public function flattenedUserLabels(): array
    {
        $flat = [];

        foreach ($this->userLabelTree() as $label) {
            $this->flattenInto($flat, $label);
        }

        return $flat;
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function loadLabelCounts(): void
    {
        if (null !== $this->labelCounts) {
            return;
        }

        $user = $this->security->getUser();

        if (null === $user) {
            $this->labelCounts = [];
        } else {
            $this->labelCounts = $this->threadRepository->countUnreadPerUserLabel($user);
        }
    }

    /**
     * @return Label[]
     */
    private function getVisibleLabels(): array
    {
        if (null === $this->visibleLabels) {
            $user = $this->security->getUser();

            if (null === $user) {
                $this->visibleLabels = [];
            } else {
                $this->visibleLabels = $this->labelRepository->findVisibleForUser($user);
            }
        }

        return $this->visibleLabels;
    }

    /**
     * @param Label[] $flat
     */
    private function flattenInto(array &$flat, Label $label): void
    {
        $flat[] = $label;

        foreach ($this->visibleChildrenOf($label) as $child) {
            $this->flattenInto($flat, $child);
        }
    }
}
