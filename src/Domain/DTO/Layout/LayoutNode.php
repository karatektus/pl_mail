<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\PaneKind;
use App\Domain\Enum\Layout\SplitDirection;

/**
 * One node of a workspace's layout tree (#755): a pane, or a split of its box
 * into two or more children.
 *
 * The stored shape is the one the browser edits, so the page and the server
 * read the same document:
 *
 *     {"t": "split", "id": "s1", "dir": "row", "sizes": [0.4, 0.6], "children": [
 *         {"t": "pane", "kind": "list"},
 *         {"t": "pane", "kind": "message"}
 *     ]}
 *
 * fromArray() is the one way in, and it is where everything the browser sends
 * is checked: a document that does not parse is refused whole rather than
 * repaired into something the user did not arrange.
 */
abstract readonly class LayoutNode
{
    /** How deep a tree may go. Five panes need at most four levels; this is a guard, not a design limit. */
    private const int MAX_DEPTH = 8;

    /**
     * Parse one node, or null if it is not a valid node.
     *
     * Two things are repaired rather than refused, because both are what the
     * browser's own edits produce in passing and neither changes what is drawn:
     * a split left with one child is that child, and a split inside a split of
     * the same direction is folded into its parent, with the sizes multiplied
     * through. Everything else that is wrong is a bug or a forgery.
     */
    public static function fromArray(mixed $data, int $depth = 0): ?self
    {
        if (false === is_array($data) || $depth > self::MAX_DEPTH) {
            return null;
        }

        return match ($data['t'] ?? null) {
            'pane'  => LayoutPane::parse($data),
            'split' => LayoutSplit::parse($data, $depth),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /**
     * Every pane kind in this subtree, in the order they are drawn.
     *
     * @return list<PaneKind>
     */
    abstract public function kinds(): array;

    /**
     * Every pane in this subtree, in the order they are drawn.
     *
     * @return list<LayoutPane>
     */
    abstract public function panes(): array;

    /**
     * Every pane's key (LayoutPane::key()), in the order they are drawn.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (LayoutPane $pane): string => $pane->key(), $this->panes());
    }

    /**
     * This subtree with each pane passed through `$map` (which returns it, or
     * a pane to put in its place), every split kept as it is.
     *
     * @param callable(LayoutPane): LayoutPane $map
     */
    abstract public function mapPanes(callable $map): self;

    /** Whether this subtree is the split of that id or contains it. */
    abstract public function hasSplit(string $id): bool;

    /** A pane of one kind. */
    public static function pane(PaneKind $kind): LayoutPane
    {
        return new LayoutPane($kind);
    }

    /**
     * A split. Sizes default to equal shares.
     *
     * @param list<LayoutNode> $children
     * @param list<float>|null $sizes
     */
    public static function split(string $id, SplitDirection $direction, array $children, ?array $sizes = null): LayoutSplit
    {
        return new LayoutSplit($id, $direction, $children, $sizes ?? array_fill(0, count($children), 1 / count($children)));
    }
}
