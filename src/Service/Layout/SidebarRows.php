<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Domain\DTO\Layout\Sidebar;
use App\Domain\Enum\Mail\SidebarRow;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Calendar\Calendar\CalendarRepository;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Task\TaskListRepository;
use App\Service\Mail\UnifiedInboxes;
use App\Service\Mail\UserAccounts;
use App\Twig\UnifiedInboxExtension;

/**
 * A sidebar of the user's own (#783), made into rows the template can draw
 * for the workspace on screen.
 *
 * Each row is an array with a `t`: the same names as the stored entries
 * (Sidebar), with the thing it points at resolved —
 *
 *   ['t' => 'sys',   'row' => SidebarRow]
 *   ['t' => 'inbox', 'inbox' => UnifiedInbox, 'first' => bool]
 *   ['t' => 'acc',   'account' => Account]
 *   ['t' => 'label', 'label' => Label]
 *   ['t' => 'cal',   'calendar' => Calendar, 'home' => ?string]  home: a local calendar's workspace
 *   ['t' => 'tasks', 'list' => TaskList, 'home' => ?string]
 *   ['t' => 'head',  'text' => string]
 *   ['t' => 'accounts']                      the account list, which has its own partial
 *
 * A live block becomes the rows it holds now, narrowed to the workspace's
 * accounts. A fixed link is drawn as it is, even outside the scope: somebody
 * who put "Inbox of hello@" in a Work sidebar meant it. An id that no longer
 * exists is left out.
 *
 * A calendar or task list with no account (a local one) belongs to the
 * workspace it was placed in, the first unless the user chose
 * (WorkspaceScope::homeOf()).
 */
final readonly class SidebarRows
{
    public function __construct(
        private WorkspaceScope     $scope,
        private UnifiedInboxes     $inboxes,
        private UserAccounts       $accounts,
        private LabelRepository    $labels,
        private CalendarRepository $calendars,
        private TaskListRepository $taskLists,
        private UnifiedInboxExtension $inboxNames,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function rows(User $user, Sidebar $sidebar): array
    {
        $rows = [];

        foreach ($sidebar->entries as $entry) {
            array_push($rows, ...$this->expand($user, $entry));
        }

        return $rows;
    }

    /**
     * @param array<string, int|string> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function expand(User $user, array $entry): array
    {
        switch ($entry['t']) {
            case 'head':
                return [['t' => 'head', 'text' => (string) $entry['text']]];

            case 'clock':
                return [['t' => 'clock']];

            case 'sys':
                $row = SidebarRow::tryFrom((string) $entry['row']);

                return null === $row ? [] : [['t' => 'sys', 'row' => $row]];

            case 'acc':
                $account = $this->account($user, (int) $entry['acc']);

                return null === $account ? [] : [['t' => 'acc', 'account' => $account]];

            case 'inbox':
                $inbox = $this->inboxes->find($user, (int) $entry['inbox']);

                return null === $inbox ? [] : [['t' => 'inbox', 'inbox' => $inbox, 'first' => false]];

            case 'label':
                $label = $this->labels->find((int) $entry['label']);

                return null === $label || $label->usr?->id !== $user->id ? [] : [['t' => 'label', 'label' => $label]];

            case 'cal':
                $calendar = $this->calendars->findOneForUser($user, (int) $entry['cal']);

                return null === $calendar ? [] : [['t' => 'cal', 'calendar' => $calendar, 'home' => $this->scope->homeOf($user, $calendar)]];

            case 'tasks':
                $list = $this->taskLists->findOwned($user, (int) $entry['list']);

                return null === $list ? [] : [['t' => 'tasks', 'list' => $list, 'home' => $this->scope->homeOf($user, $list)]];

            case 'block':
                return $this->block($user, (string) $entry['b']);
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function block(User $user, string $block): array
    {

        switch ($block) {
            case 'accounts':
                return [['t' => 'accounts']];

            case 'inboxes':
                $first = $this->inboxes->first($user);
                $rows  = [];

                $groups = $this->scope->groupIds($user);

                foreach ($this->inboxes->shown($user) as $inbox) {
                    if (null === $groups || in_array((int) $inbox->id, $groups, true)) {
                        $rows[] = ['t' => 'inbox', 'inbox' => $inbox, 'first' => $inbox->id === $first->id];
                    }
                }

                return $rows;

            case 'labels':
                return array_map(
                    static fn ($label): array => ['t' => 'label', 'label' => $label],
                    array_values(array_filter($this->labels->findVisibleForUser($user), static fn ($label): bool => null === $label->role && null === $label->parent)),
                );

            case 'calendars':
                // A local calendar is in the workspace it was placed in.
                $calendars = $this->scope->calendarIds($user);

                return array_map(
                    fn (Calendar $calendar): array => ['t' => 'cal', 'calendar' => $calendar, 'home' => $this->scope->homeOf($user, $calendar)],
                    array_values(array_filter($this->calendars->findForUser($user), static fn (Calendar $calendar): bool => null === $calendars || in_array((int) $calendar->id, $calendars, true))),
                );

            case 'tasklists':
                $lists = $this->scope->taskListIds($user);

                return array_map(
                    fn (TaskList $list): array => ['t' => 'tasks', 'list' => $list, 'home' => $this->scope->homeOf($user, $list)],
                    array_values(array_filter($this->taskLists->findForUser($user), static fn (TaskList $list): bool => null === $lists || in_array((int) $list->id, $lists, true))),
                );
        }

        return [];
    }

    /**
     * Everything the editor can add, by group, each as the entry to store and
     * the name to show — also what the editor shows for an entry already in
     * a sidebar. Names are the user's own (labels, calendars) or translation
     * keys, marked by the `key:` prefix, for the template to translate.
     *
     * @return array<string, list<array{entry: array<string, int|string>, name: string}>>
     */
    public function catalog(User $user): array
    {
        $blocks = array_map(static fn (string $b): array => ['entry' => ['t' => 'block', 'b' => $b], 'name' => 'key:layout.sidebar.block.' . $b], Sidebar::BLOCKS);

        $mail = array_map(static fn (SidebarRow $row): array => ['entry' => ['t' => 'sys', 'row' => $row->value], 'name' => 'key:sidebar.nav.' . $row->value], SidebarRow::cases());

        foreach ($this->accounts->inMail($user) as $account) {
            $mail[] = ['entry' => ['t' => 'acc', 'acc' => (int) $account->id], 'name' => (string) ($account->name ?: $account->displayAddress)];
        }

        foreach ($this->inboxes->shown($user) as $inbox) {
            $mail[] = ['entry' => ['t' => 'inbox', 'inbox' => (int) $inbox->id], 'name' => $this->inboxNames->name($inbox)];
        }

        $labels = [];

        foreach ($this->labels->findVisibleForUser($user) as $label) {
            if (null === $label->role) {
                $labels[] = ['entry' => ['t' => 'label', 'label' => (int) $label->id], 'name' => (string) $label->name];
            }
        }

        $calendars = array_map(static fn (Calendar $calendar): array => ['entry' => ['t' => 'cal', 'cal' => (int) $calendar->id], 'name' => $calendar->name], $this->calendars->findForUser($user));
        $lists     = array_map(static fn (TaskList $list): array => ['entry' => ['t' => 'tasks', 'list' => (int) $list->id], 'name' => $list->name], $this->taskLists->findForUser($user));

        return [
            'other'     => [['entry' => ['t' => 'clock'], 'name' => 'key:layout.sidebar.clock']],
            'blocks'    => $blocks,
            'mail'      => $mail,
            'labels'    => $labels,
            'calendars' => $calendars,
            'tasks'     => $lists,
        ];
    }

    private function account(User $user, int $id): ?Account
    {
        foreach ($this->accounts->inMail($user) as $account) {
            if ((int) $account->id === $id) {
                return $account;
            }
        }

        return null;
    }
}
