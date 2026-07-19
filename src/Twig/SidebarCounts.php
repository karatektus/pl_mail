<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\LabelRole;
use App\Entity\Label;
use App\Repository\LabelRepository;
use App\Repository\MessageThreadRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Per-request cache of sidebar counts and the user label tree,
 * label-based since the refactor.
 */
class SidebarCounts
{
    private ?array $roleCounts = null;
    private ?array $labelCounts = null;
    private ?int $starredCount = null;
    private ?array $userLabelRoots = null;

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
        if (null === $this->labelCounts) {
            $user = $this->security->getUser();

            if (null === $user) {
                $this->labelCounts = [];
            } else {
                $this->labelCounts = $this->threadRepository->countUnreadPerUserLabel($user);
            }
        }

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
     * Root user labels (role null, visible, no parent) across all active
     * accounts, for the sidebar tree. Children render via label.children.
     *
     * @return Label[]
     */
    public function userLabelRoots(): array
    {
        if (null === $this->userLabelRoots) {
            $user = $this->security->getUser();

            if (null === $user) {
                $this->userLabelRoots = [];
            } else {
                $roots = [];

                foreach ($this->labelRepository->findVisibleForUser($user) as $label) {
                    if (false === $label->isSystem && null === $label->parent) {
                        $roots[] = $label;
                    }
                }

                $this->userLabelRoots = $roots;
            }
        }

        return $this->userLabelRoots;
    }

    /**
     * Depth-first flattening of the user label tree, for the "Label as"
     * dropdown. Indentation in the template derives from label.depth.
     *
     * @return Label[]
     */
    public function userLabelsFlat(): array
    {
        $flat = [];

        foreach ($this->userLabelRoots() as $root) {
            $this->flatten($root, $flat);
        }

        return $flat;
    }

    /**
     * @param Label[] $flat
     */
    private function flatten(Label $label, array &$flat): void
    {
        $flat[] = $label;

        foreach ($label->children as $child) {
            if (false === $child->isVisible) {
                continue;
            }

            if (true === $child->isSystem) {
                continue;
            }

            $this->flatten($child, $flat);
        }
    }
}
