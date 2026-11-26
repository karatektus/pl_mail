<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Domain\DTO\Layout\LayoutNode;
use App\Domain\DTO\Layout\Workspace;
use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Layout\PaneKind;
use App\Domain\Enum\Layout\SplitDirection;
use App\Entity\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A user's workspaces (#755): read from the settings bag, or — for somebody
 * who has never arranged anything — made from the panes they had open before
 * workspaces existed.
 *
 * ── The first workspace is the old layout ─────────────────────────────────
 *
 * Before #755 the row was fixed: the mail, the message beside it if the
 * reading pane was on, then the calendar, the address book and the tasks if
 * each was docked. Those settings are read ONCE here, by their stored keys,
 * and turned into a workspace that looks like what the user had. Nothing is
 * written until the user changes something; until then every page derives the
 * same answer, so the old keys are left where they are and a later chore can
 * drop them.
 *
 * Widths become shares of a reference width, because the old docks stored
 * pixels and a layout tree stores proportions. 1200px is a 1440px window minus
 * the sidebar, which is the screen most of these widths were dragged on. A
 * device's own divider positions are not taken from the old settings at all:
 * the first drag on each device writes its own (DeviceLayout).
 */
final readonly class WorkspaceStore
{
    /** Read only to derive the first workspace; see the class note. */
    private const string LEGACY_READING_MODE   = 'reading.pane_mode';
    private const string LEGACY_READING_PCT    = 'reading.pane_width_pct';
    private const string LEGACY_CALENDAR_MODE  = 'calendar.pane_mode';
    private const string LEGACY_CALENDAR_OPEN  = 'calendar.pane_open';
    private const string LEGACY_CALENDAR_WIDTH = 'calendar.pane_width';
    private const string LEGACY_CONTACTS_MODE  = 'contacts.pane_mode';
    private const string LEGACY_CONTACTS_OPEN  = 'contacts.pane_open';
    private const string LEGACY_CONTACTS_WIDTH = 'contacts.pane_width';
    private const string LEGACY_TASKS_OPEN     = 'tasks.pane_open';
    private const string LEGACY_TASKS_WIDTH    = 'tasks.pane_width';

    /** The width the old pixel widths are turned into shares of. See the class note. */
    private const int REFERENCE_WIDTH = 1200;

    public function __construct(
        private TranslatorInterface $translator,
        private WorkspacePin        $pin,
    ) {
    }

    /**
     * The user's workspaces, the active one being the pinned one when a
     * request pinned one of theirs (WorkspacePin).
     */
    public function load(User $user): Workspaces
    {
        $workspaces = Workspaces::fromArray($user->getSetting(User::SETTING_WORKSPACES)) ?? $this->initial($user);
        $pinned     = $this->pin->pinned();

        if (null !== $pinned) {
            foreach ($workspaces->all as $index => $workspace) {
                if ($workspace->id === $pinned) {
                    return new Workspaces($workspaces->all, $index);
                }
            }
        }

        return $workspaces;
    }

    public function save(User $user, Workspaces $workspaces): void
    {
        $user->setSetting(User::SETTING_WORKSPACES, $workspaces->toArray());
    }

    /**
     * The one workspace somebody starts with: what they had open before #755,
     * or, for a new account, the mail with the calendar beside it — the old
     * default, kept because a closed calendar is one nobody finds.
     */
    public function initial(User $user): Workspaces
    {
        $docks = [];

        if ($this->calendarWasOpen($user)) {
            $docks[] = [PaneKind::Calendar, $this->share($user->getSetting(self::LEGACY_CALENDAR_WIDTH), 380)];
        }

        if ($this->contactsWereOpen($user)) {
            $docks[] = [PaneKind::Contacts, $this->share($user->getSetting(self::LEGACY_CONTACTS_WIDTH), 420)];
        }

        if (true === $user->getSetting(self::LEGACY_TASKS_OPEN, false)) {
            $docks[] = [PaneKind::Tasks, $this->share($user->getSetting(self::LEGACY_TASKS_WIDTH), 380)];
        }

        $dockShare = array_sum(array_column($docks, 1));

        // The docks never left the mail less than a third of the row; three
        // wide ones stored on a big monitor would, on a share basis.
        if ($dockShare > 0.66) {
            $docks     = array_map(static fn (array $dock): array => [$dock[0], $dock[1] * 0.66 / $dockShare], $docks);
            $dockShare = 0.66;
        }

        $mailShare = 1 - $dockShare;
        $children  = [LayoutNode::pane(PaneKind::List)];
        $sizes     = [$mailShare];

        if ('right' === $user->getSetting(self::LEGACY_READING_MODE)) {
            $pct        = $user->getSetting(self::LEGACY_READING_PCT, 55);
            $message    = (is_int($pct) ? max(25, min(75, $pct)) : 55) / 100;
            $children[] = LayoutNode::pane(PaneKind::Message);
            $sizes      = [$mailShare * (1 - $message), $mailShare * $message];
        }

        foreach ($docks as [$kind, $share]) {
            $children[] = LayoutNode::pane($kind);
            $sizes[]    = $share;
        }

        $root = 1 === count($children)
            ? $children[0]
            : LayoutNode::split('s1', SplitDirection::Row, $children, $sizes);

        // In the request's locale, which UserLocaleSubscriber has already set
        // to the user's own: the name is stored as text from here on, and it
        // should be the word the user reads everywhere else.
        $name = $this->translator->trans('layout.workspace.first_name');

        return new Workspaces([new Workspace('w1', $name, $root)], 0);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The calendar's old position, read as CalendarPaneMode read it: an
     * explicit mode, else the older boolean, where only an explicit `false`
     * meant shut — a missing key was the default, which was the pane beside
     * the mail.
     */
    private function calendarWasOpen(User $user): bool
    {
        $mode = $user->getSetting(self::LEGACY_CALENDAR_MODE);

        if (true === in_array($mode, ['mail', 'split', 'calendar'], true)) {
            return 'mail' !== $mode;
        }

        return false !== $user->getSetting(self::LEGACY_CALENDAR_OPEN);
    }

    /** The address book's old position, as ContactsPaneMode read it. */
    private function contactsWereOpen(User $user): bool
    {
        $mode = $user->getSetting(self::LEGACY_CONTACTS_MODE);

        if (true === in_array($mode, ['mail', 'split', 'contacts'], true)) {
            return 'mail' !== $mode;
        }

        return true === $user->getSetting(self::LEGACY_CONTACTS_OPEN, false);
    }

    /** A stored pixel width as a share of the reference width. */
    private function share(mixed $width, int $default): float
    {
        $pixels = is_int($width) && $width > 0 ? $width : $default;

        return max(0.2, min(0.5, $pixels / self::REFERENCE_WIDTH));
    }
}
