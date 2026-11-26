<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Domain\DTO\Layout\Sidebar;
use App\Entity\User\User;

/**
 * A user's own sidebars (#783), in their settings bag beside the workspaces
 * that use them. A list, read and written whole, for the reason workspaces
 * are: the editor sends them whole, and there are only ever a few.
 *
 * The built-in Mail sidebar is not stored: it is the template, and every
 * workspace without a choice uses it (Workspace::SIDEBAR_MAIL).
 */
final readonly class SidebarStore
{
    public const int MAX = 12;

    /** @return array<string, Sidebar> by id, in the user's order */
    public function load(User $user): array
    {
        $stored = $user->getSetting(User::SETTING_SIDEBARS);
        $out    = [];

        foreach (is_array($stored) && array_is_list($stored) ? $stored : [] as $item) {
            $sidebar = Sidebar::fromArray($item);

            if (null !== $sidebar && false === isset($out[$sidebar->id]) && count($out) < self::MAX) {
                $out[$sidebar->id] = $sidebar;
            }
        }

        return $out;
    }

    /** @param array<string, Sidebar>|list<Sidebar> $sidebars */
    public function save(User $user, array $sidebars): void
    {
        $user->setSetting(User::SETTING_SIDEBARS, array_values(array_map(static fn (Sidebar $sidebar): array => $sidebar->toArray(), $sidebars)));
    }

    public function find(User $user, ?string $id): ?Sidebar
    {
        return null === $id ? null : ($this->load($user)[$id] ?? null);
    }
}
