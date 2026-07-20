<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Render-time view model for the sidebar label tree.
 *
 * Labels are per-account rows; the sidebar merges same-named labels across
 * all active accounts into one node per path segment. A node carries the
 * ids of every underlying Label so unread counts can be summed and the
 * path-based label view can query threads across accounts.
 */
final class LabelTreeNode
{
    /** Display name, original casing of the first label merged in. */
    public string $name;

    /** Full path ("Work/Invoices"), used as the route parameter. */
    public string $path;

    /** First non-null color among the merged labels. */
    public ?string $color = null;

    /** @var list<int> ids of all merged Label rows */
    public array $labelIds = [];

    /** @var array<string, LabelTreeNode> keyed by lowercased child name */
    public array $children = [];

    public function __construct(string $name, string $path)
    {
        $this->name = $name;
        $this->path = $path;
    }
}
