<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\PaneKind;

/**
 * Everything a user has arranged (#755): their workspaces, in the order the
 * top bar shows them, and which one is on screen.
 *
 * Stored whole in the user's settings bag (User::SETTING_WORKSPACES) and
 * posted whole by the page on every change. One document rather than a row
 * per workspace because it is always read and written together — the top bar
 * lists every workspace on every page — and because a reorder, a rename and a
 * switch are each one small edit to it.
 */
final readonly class Workspaces
{
    /** As many as ⌥1–⌥9 can reach. */
    public const int MAX = 9;

    /** @param list<Workspace> $all */
    public function __construct(
        public array $all,
        public int   $activeIndex,
    ) {
    }

    /**
     * Parse the stored or posted document, or null if any part of it is
     * invalid. An active index out of range is the one thing taken leniently:
     * it points at the first workspace, which is a harmless answer to a
     * workspace that was just deleted on another device.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (false === is_array($data) || false === is_array($data['workspaces'] ?? null) || false === array_is_list($data['workspaces'])) {
            return null;
        }

        $count = count($data['workspaces']);

        if (0 === $count || $count > self::MAX) {
            return null;
        }

        $all = [];
        $ids = [];

        foreach ($data['workspaces'] as $item) {
            $workspace = Workspace::fromArray($item);

            if (null === $workspace || true === isset($ids[$workspace->id])) {
                return null;
            }

            $ids[$workspace->id] = true;
            $all[]               = $workspace;
        }

        $active = is_int($data['active'] ?? null) ? $data['active'] : 0;

        return new self(self::ownCalendars($all), $active >= 0 && $active < $count ? $active : 0);
    }

    /**
     * Every calendar its own pane, across workspaces.
     *
     * A calendar is one pane wherever its key appears — one frame, one view
     * remembered (User::SETTING_CALENDAR_PANE_VIEWS) — so a calendar in
     * "Mail" and one in "Planning" with the same key were the same calendar:
     * picking Agenda in one switched the other. Each workspace was made with
     * a plain `{"kind": "calendar"}` before calendars could come twice, so
     * every document from then has exactly that. The first workspace's keeps
     * its key; each later repeat gets an id of its own, derived from its
     * workspace so the same document gives the same ids on every read and
     * the view picked in it is still there tomorrow without anything being
     * written. The page gives new workspaces' calendars ids of their own as
     * it makes them (layout--workspace), so this only ever meets the old ones.
     *
     * @param list<Workspace> $all
     *
     * @return list<Workspace>
     */
    private static function ownCalendars(array $all): array
    {
        $taken = [];

        foreach ($all as $workspace) {
            foreach ($workspace->root->keys() as $key) {
                $taken[$key] = 0;
            }
        }

        $seen = [];

        return array_map(static function (Workspace $workspace) use (&$seen, &$taken): Workspace {
            $root = $workspace->root->mapPanes(static function (LayoutPane $pane) use (&$seen, &$taken, $workspace): LayoutPane {
                if (PaneKind::Calendar !== $pane->kind) {
                    return $pane;
                }

                if (false === isset($seen[$pane->key()])) {
                    $seen[$pane->key()] = true;

                    return $pane;
                }

                $salt = 0;

                do {
                    $id = 'c' . substr(hash('crc32b', $workspace->id . '~' . $pane->key() . '~' . $salt++), 0, 8);
                } while (isset($taken[PaneKind::Calendar->value . '~' . $id]));

                $taken[PaneKind::Calendar->value . '~' . $id] = 0;
                $seen[PaneKind::Calendar->value . '~' . $id]  = true;

                return new LayoutPane(PaneKind::Calendar, $id);
            });

            return $root === $workspace->root ? $workspace : new Workspace($workspace->id, $workspace->name, $root);
        }, $all);
    }

    /** @return array{workspaces: list<array<string, mixed>>, active: int} */
    public function toArray(): array
    {
        return [
            'workspaces' => array_map(static fn (Workspace $workspace): array => $workspace->toArray(), $this->all),
            'active'     => $this->activeIndex,
        ];
    }

    public function active(): Workspace
    {
        return $this->all[$this->activeIndex];
    }
}
