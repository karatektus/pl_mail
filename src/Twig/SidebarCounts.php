<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\LabelRole;
use App\Domain\Model\LabelTreeNode;
use App\Entity\Label;
use App\Repository\LabelRepository;
use App\Repository\MessageThreadRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Per-request cache of sidebar counts and the user label tree.
 *
 * Since labels are per-account rows, the sidebar merges same-named labels
 * across all active accounts into one LabelTreeNode per path — two Gmail
 * accounts each carrying "Templates" render as a single entry whose unread
 * badge sums both.
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

    /**
     * Unread total of a merged tree node — the sum over every underlying
     * Label row across accounts.
     */
    public function forNode(LabelTreeNode $node): int
    {
        $this->loadLabelCounts();

        $sum = 0;

        foreach ($node->labelIds as $labelId) {
            $sum += $this->labelCounts[$labelId] ?? 0;
        }

        return $sum;
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
     * Merged user label tree (custom labels only) across all active
     * accounts, one node per path, sorted case-insensitively per level.
     *
     * @return LabelTreeNode[]
     */
    public function userLabelTree(): array
    {
        if (null === $this->userLabelTree) {
            $roots = [];

            foreach ($this->getVisibleLabels() as $label) {
                if (true === $label->isSystem || null !== $label->parent) {
                    continue;
                }

                $this->mergeInto($roots, $label, '');
            }

            $this->sortNodes($roots);

            $this->userLabelTree = array_values($roots);
        }

        return $this->userLabelTree;
    }

    /**
     * True when at least one account has its Archive label switched
     * visible — controls the Archive entry in the system nav block.
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

        foreach ($this->getVisibleLabels() as $label) {
            if (true === $label->isSystem || null !== $label->parent) {
                continue;
            }

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
     * @param array<string, LabelTreeNode> $bucket
     */
    private function mergeInto(array &$bucket, Label $label, string $parentPath): void
    {
        $name = (string) $label->name;
        $key  = mb_strtolower($name);
        $path = '' !== $parentPath ? $parentPath . '/' . $name : $name;

        if (false === array_key_exists($key, $bucket)) {
            $bucket[$key] = new LabelTreeNode($name, $path);
        }

        $node = $bucket[$key];

        $node->labelIds[] = (int) $label->id;

        if (null === $node->color && null !== $label->color) {
            $node->color = $label->color;
        }

        foreach ($label->children as $child) {
            if (true !== $child->isVisible) {
                continue;
            }

            $this->mergeInto($node->children, $child, $node->path);
        }
    }

    /**
     * @param array<string, LabelTreeNode> $nodes
     */
    private function sortNodes(array &$nodes): void
    {
        ksort($nodes);

        foreach ($nodes as $node) {
            $this->sortNodes($node->children);
            $node->children = array_values($node->children);
        }
    }

    /**
     * @param Label[] $flat
     */
    private function flattenInto(array &$flat, Label $label): void
    {
        $flat[] = $label;

        foreach ($label->children as $child) {
            if (true !== $child->isVisible) {
                continue;
            }

            $this->flattenInto($flat, $child);
        }
    }
}
