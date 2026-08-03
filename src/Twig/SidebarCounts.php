<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageThreadRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Per-request cache of sidebar counts and the user label tree.
 *
 * Labels are user-scoped, so the tree is read straight from the repository.
 * This class used to merge same-named labels across accounts into a
 * LabelTreeNode per path — reconstructing at render time the unified label the
 * data model did not have. LabelBinding made that entity real, so the merge,
 * the node DTO and the id-summing counters are gone.
 */
class SidebarCounts
{
    private ?array $roleCounts = null;
    private ?array $labelCounts = null;
    private ?int $starredCount = null;
    private ?int $snoozedCount = null;
    private ?array $userLabelTree = null;
    private ?array $visibleLabels = null;
    /** @var array<int, list<Label>> account id => its visible labels */
    private array $accountLabels = [];
    /** @var array<int, array<int,int>> account id => (label id => unread) */
    private array $accountLabelCounts = [];

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

        foreach ($label->children as $child) {
            if (true === $child->isVisible) {
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
        foreach ($this->getVisibleLabels() as $label) {
            if (LabelRole::Archive === $label->role) {
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
