<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
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
    private ?array $userLabelTree = null;
    private ?array $visibleLabels = null;

    public function __construct(
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly Security                $security,
    ) {}

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
