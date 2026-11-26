<?php

declare(strict_types=1);

namespace App\Service\Layout;

use Symfony\Contracts\Service\ResetInterface;

/**
 * A workspace to draw for, for the rest of one request (#783), whatever the
 * user's saved choice says. The redraw stream sets it: a switch asks for the
 * new workspace's sidebar, list and panes before its save has landed, and the
 * request names the workspace instead of waiting for it.
 *
 * WorkspaceStore::load() reads it, so everything that asks which workspace is
 * on screen — the scope, the sidebar, the Twig globals — gets the pinned one.
 * Nothing is written. Reset between requests for the worker runtime.
 */
final class WorkspacePin implements ResetInterface
{
    private ?string $id = null;

    public function pin(string $workspaceId): void
    {
        $this->id = $workspaceId;
    }

    public function pinned(): ?string
    {
        return $this->id;
    }

    public function reset(): void
    {
        $this->id = null;
    }
}
