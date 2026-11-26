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

    /** The sidebar every workspace had before #783, and still has unless it picks another. */
    public const string SIDEBAR_MAIL = 'mail';

    /** What a sidebar link does when the workspace has no pane for it (#783). */
    public const string MISSING_SWITCH = 'switch';
    public const string MISSING_EDGE   = 'edge';

    /**
     * @param list<int>|null        $groups   the account groups it shows (#783); null is every account
     * @param string|null           $sidebar  a sidebar id, SIDEBAR_MAIL, or null for none
     * @param array<string, string> $targets  pane kind => the key of the pane its sidebar links open in
     */
    public function __construct(
        public string     $id,
        public string     $name,
        public LayoutNode $root,
        public ?array     $groups = null,
        public ?string    $sidebar = self::SIDEBAR_MAIL,
        public array      $targets = [],
        public string     $missing = self::MISSING_SWITCH,
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

        // #783's keys, each read leniently: a workspace saved before them has
        // none, and has to come out exactly as it was — every account, the
        // mail sidebar. Anything malformed falls back to that same answer.
        $groups = $data['groups'] ?? null;
        $groups = is_array($groups) && array_is_list($groups) && [] !== $groups
            ? array_values(array_unique(array_filter($groups, 'is_int')))
            : null;

        $sidebar = array_key_exists('sidebar', $data) ? $data['sidebar'] : self::SIDEBAR_MAIL;
        $sidebar = null === $sidebar || (is_string($sidebar) && 1 === preg_match(self::ID_PATTERN, $sidebar)) ? $sidebar : self::SIDEBAR_MAIL;

        $targets = [];

        foreach (is_array($data['targets'] ?? null) ? $data['targets'] : [] as $kind => $key) {
            if (is_string($kind) && null !== PaneKind::tryFrom($kind) && is_string($key) && true === in_array($key, $keys, true)) {
                $targets[$kind] = $key;
            }
        }

        $missing = self::MISSING_EDGE === ($data['missing'] ?? null) ? self::MISSING_EDGE : self::MISSING_SWITCH;

        return new self($id, $name, $root, [] === $groups ? null : $groups, $sidebar, $targets, $missing);
    }

    /** @return array{id: string, name: string, layout: array<string, mixed>, groups: list<int>|null, sidebar: string|null, targets: object, missing: string} */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'layout'   => $this->root->toArray(),
            'groups'   => $this->groups,
            'sidebar'  => $this->sidebar,
            'targets'  => (object) $this->targets,
            'missing'  => $this->missing,
        ];
    }

    /** The same workspace with another tree — the parts #783 added carried over. */
    public function withRoot(LayoutNode $root): self
    {
        return new self($this->id, $this->name, $root, $this->groups, $this->sidebar, $this->targets, $this->missing);
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
