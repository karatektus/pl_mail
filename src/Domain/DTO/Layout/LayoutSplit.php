<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\SplitDirection;

/**
 * A node that divides its box between two or more children, side by side or
 * above and below.
 *
 * `sizes` are shares of the box, one per child, kept summing to 1. They are
 * the workspace's proportions, the ones a new device starts from; the exact
 * positions somebody dragged the dividers to on THIS device are not stored
 * here but in the device's cookie, keyed by this split's id (see
 * DeviceLayout). That is why a split has an id and a pane does not.
 */
final readonly class LayoutSplit extends LayoutNode
{
    /** What a split id may be: the browser makes them up, so they are checked like any other input. */
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    /** A share smaller than this is a pane nobody could see; the browser's dividers never produce one. */
    private const float MIN_SHARE = 0.02;

    /**
     * @param list<LayoutNode> $children
     * @param list<float>      $sizes
     */
    public function __construct(
        public string         $id,
        public SplitDirection $direction,
        public array          $children,
        public array          $sizes,
    ) {
    }

    /** @param array<mixed> $data */
    public static function parse(array $data, int $depth): ?LayoutNode
    {
        $id        = $data['id'] ?? null;
        $direction = SplitDirection::tryFrom(is_string($data['dir'] ?? null) ? $data['dir'] : '');
        $children  = $data['children'] ?? null;
        $sizes     = $data['sizes'] ?? null;

        if (false === is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id) || null === $direction) {
            return null;
        }

        if (false === is_array($children) || false === array_is_list($children) || [] === $children) {
            return null;
        }

        if (false === is_array($sizes) || count($sizes) !== count($children)) {
            return null;
        }

        $nodes  = [];
        $shares = [];

        foreach ($children as $i => $child) {
            $node = LayoutNode::fromArray($child, $depth + 1);
            $size = $sizes[$i] ?? null;

            if (null === $node || (false === is_int($size) && false === is_float($size)) || false === is_finite((float) $size) || $size <= 0) {
                return null;
            }

            // Same direction inside the same direction: one row of three, not
            // a row holding a row of two. Drawn identically, and the browser's
            // Move and Split assume the flat form when they look for siblings.
            if ($node instanceof self && $node->direction === $direction) {
                foreach ($node->children as $j => $grandchild) {
                    $nodes[]  = $grandchild;
                    $shares[] = (float) $size * $node->sizes[$j];
                }

                continue;
            }

            $nodes[]  = $node;
            $shares[] = (float) $size;
        }

        if (1 === count($nodes)) {
            return $nodes[0];
        }

        $total = array_sum($shares);
        $sizes = array_map(static fn (float $share): float => $share / $total, $shares);

        foreach ($sizes as $share) {
            if ($share < self::MIN_SHARE) {
                return null;
            }
        }

        return new self($id, $direction, $nodes, $sizes);
    }

    public function toArray(): array
    {
        return [
            't'        => 'split',
            'id'       => $this->id,
            'dir'      => $this->direction->value,
            'sizes'    => array_map(static fn (float $share): float => round($share, 4), $this->sizes),
            'children' => array_map(static fn (LayoutNode $child): array => $child->toArray(), $this->children),
        ];
    }

    public function kinds(): array
    {
        return array_merge(...array_map(static fn (LayoutNode $child): array => $child->kinds(), $this->children));
    }

    public function hasSplit(string $id): bool
    {
        if ($id === $this->id) {
            return true;
        }

        foreach ($this->children as $child) {
            if (true === $child->hasSplit($id)) {
                return true;
            }
        }

        return false;
    }
}
