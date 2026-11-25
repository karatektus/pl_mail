<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

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

        return new self($all, $active >= 0 && $active < $count ? $active : 0);
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
