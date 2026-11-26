<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\PaneKind;

/**
 * A leaf of the layout tree: one pane, named by what it shows.
 *
 * Most kinds appear once in a workspace and are named by their kind alone. A
 * calendar may appear more than once, each keeping its own view (one on the
 * week, one on the month): the first is `{"kind": "calendar"}` like any other
 * pane, and each further one carries an `id`, which is what tells them apart —
 * in the page, where each has its own frame, and in the per-pane view the
 * calendar remembers (User::SETTING_CALENDAR_PANE_VIEWS). key() is that name:
 * the kind, or `calendar~<id>`.
 */
final readonly class LayoutPane extends LayoutNode
{
    /** Short, because it ends up in a frame id, a URL and a cookie. */
    public const string ID_PATTERN = '/^[a-z0-9]{1,16}$/';

    public function __construct(
        public PaneKind $kind,
        public ?string  $id = null,
    ) {
    }

    /**
     * One pane, or null. An `id` on anything but a calendar, or one that is
     * not ID_PATTERN, is refused: only calendars come more than once, and the
     * page never sends anything else.
     *
     * @param array<mixed> $data
     */
    public static function parse(array $data): ?self
    {
        $kind = PaneKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $id   = $data['id'] ?? null;

        if (null === $kind) {
            return null;
        }

        if (null === $id) {
            return new self($kind);
        }

        if (PaneKind::Calendar !== $kind || false === is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        return new self($kind, $id);
    }

    /** The pane's name in the page: its kind, or `calendar~<id>` for a further calendar. */
    public function key(): string
    {
        return null === $this->id ? $this->kind->value : $this->kind->value . '~' . $this->id;
    }

    public function toArray(): array
    {
        return null === $this->id
            ? ['t' => 'pane', 'kind' => $this->kind->value]
            : ['t' => 'pane', 'kind' => $this->kind->value, 'id' => $this->id];
    }

    public function kinds(): array
    {
        return [$this->kind];
    }

    public function panes(): array
    {
        return [$this];
    }

    public function hasSplit(string $id): bool
    {
        return false;
    }
}
