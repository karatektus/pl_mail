<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\PaneKind;

/**
 * A named arrangement of panes (#755): "Triage", "Planning", whatever the
 * user calls theirs. Switched from the top bar, edited in edit mode, made from
 * a shape in the Layout menu.
 */
final readonly class Workspace
{
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    /** Calendars beside each other, each on its own view; past four they are slivers. */
    public const int MAX_CALENDARS = 4;

    /** Long enough for "Morgens: Post und Kalender", short enough for a tab in the top bar. */
    public const int NAME_MAX_LENGTH = 40;

    public function __construct(
        public string     $id,
        public string     $name,
        public LayoutNode $root,
    ) {
    }

    /**
     * Parse one workspace, or null if it is not one.
     *
     * Refused, not repaired: a pane twice (the same key — two calendars are
     * two keys, see LayoutPane), more than MAX_CALENDARS calendars, no panes, a
     * name that is empty once trimmed. Each of those is something the page
     * never sends, so the document did not come from it.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (false === is_array($data)) {
            return null;
        }

        $id   = $data['id'] ?? null;
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $root = LayoutNode::fromArray($data['layout'] ?? null);

        if (false === is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id) || null === $root) {
            return null;
        }

        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return null;
        }

        $keys = $root->keys();

        if (count($keys) !== count(array_unique($keys))) {
            return null;
        }

        if (count(array_filter($root->kinds(), static fn (PaneKind $kind): bool => PaneKind::Calendar === $kind)) > self::MAX_CALENDARS) {
            return null;
        }

        return new self($id, $name, $root);
    }

    /** @return array{id: string, name: string, layout: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'layout' => $this->root->toArray()];
    }

    /** Whether this workspace has the pane of that key (LayoutPane::key()). */
    public function hasKey(string $key): bool
    {
        return in_array($key, $this->root->keys(), true);
    }

    /** Whether this workspace has a pane of that kind anywhere in it. */
    public function has(PaneKind $kind): bool
    {
        return in_array($kind, $this->root->kinds(), true);
    }

    /** @return list<PaneKind> the kinds this workspace does NOT show, in PaneKind order */
    public function absentKinds(): array
    {
        return array_values(array_filter(PaneKind::cases(), fn (PaneKind $kind): bool => false === $this->has($kind)));
    }
}
