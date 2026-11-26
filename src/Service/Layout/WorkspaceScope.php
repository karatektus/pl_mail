<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Domain\DTO\Layout\Workspace;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Calendar\Calendar\CalendarRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Task\TaskListRepository;
use App\Service\Mail\UnifiedInboxes;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which accounts the workspace on screen shows (#783).
 *
 * The workspace stores account groups (UnifiedInbox ids); this is where they
 * become the accounts in them. A group that is gone is dropped, and a
 * workspace left with none of its accounts shows every account rather than
 * nothing — an empty workspace reads as lost mail, which is the worse mistake.
 *
 * Null means "every account", the answer for a workspace that never chose
 * and for anything that is not the web app drawing a workspace (JMAP, the
 * workers): those never ask this service, so they are never narrowed.
 *
 * Memoised per request; reset between requests for the worker runtime.
 */
final class WorkspaceScope implements ResetInterface
{
    /** @var array<int, array{ids: list<int>|null}> by user id */
    private array $memo = [];

    /** @var array<string, list<int>|null> "user:workspace" => its accounts */
    private array $resolved = [];

    /** @var array<int, list<Workspace>> user id => their workspaces */
    private array $all = [];

    /** @var array<string, list<int>|null> "user:workspace:kind" => calendar or list ids */
    private array $things = [];

    public function __construct(
        private readonly WorkspaceStore    $workspaces,
        private readonly AccountRepository $accounts,
        private readonly UnifiedInboxes    $groups,
        private readonly CalendarRepository $calendars,
        private readonly TaskListRepository $taskLists,
    ) {
    }

    public function reset(): void
    {
        $this->memo     = [];
        $this->resolved = [];
        $this->all      = [];
        $this->things   = [];
    }

    /** @return list<int>|null the active workspace's accounts, null for all */
    public function accountIds(User $user): ?array
    {
        $key = (int) $user->id;

        if (false === isset($this->memo[$key])) {
            $this->memo[$key] = ['ids' => $this->resolve($user, $this->workspaces->load($user)->active())];
        }

        return $this->memo[$key]['ids'];
    }

    /**
     * The active workspace's account groups that still exist, in the user's
     * group order, or null for a workspace over every account (#783).
     *
     * @return list<int>|null
     */
    public function groupIds(User $user): ?array
    {
        $workspace = $this->workspaces->load($user)->active();

        if (null === $workspace->groups || null === $this->accountIds($user)) {
            return null;
        }

        $ids = [];

        foreach ($this->groups->all($user) as $group) {
            if (in_array((int) $group->id, $workspace->groups, true)) {
                $ids[] = (int) $group->id;
            }
        }

        return $ids;
    }

    /**
     * The accounts of the workspace a search names with `space:` (#783),
     * matched by name without regard to case. Null is a workspace over every
     * account; an empty list is a name that is no workspace of the user's.
     *
     * @return list<int>|null
     */
    public function forSpace(User $user, string $name): ?array
    {
        foreach ($this->workspaces->load($user)->all as $workspace) {
            if (0 === strcasecmp(trim($workspace->name), trim($name))) {
                return $this->resolve($user, $workspace);
            }
        }

        return [];
    }

    /** @return list<int>|null the given workspace's accounts, null for all */
    public function of(User $user, Workspace $workspace): ?array
    {
        return $this->resolve($user, $workspace);
    }

    /**
     * Narrow a list of account ids (a unified inbox's, say) to the scope.
     * Null in is every account; null out is every account too.
     *
     * @param list<int>|null $ids
     *
     * @return list<int>|null
     */
    public function narrow(UserInterface $user, ?array $ids): ?array
    {
        if (false === $user instanceof User) {
            return $ids;
        }

        $scope = $this->accountIds($user);

        if (null === $scope) {
            return $ids;
        }

        if (null === $ids) {
            return $scope;
        }

        return array_values(array_intersect($ids, $scope));
    }

    /**
     * The calendars a workspace shows (#783), the active one unless named:
     * null for every calendar, which is any workspace over every account.
     * A scoped one shows its accounts' calendars and the calendars without an
     * account that were placed in it (homeOf()).
     *
     * @return list<int>|null
     */
    public function calendarIds(User $user, ?Workspace $workspace = null): ?array
    {
        return $this->idsIn($user, $workspace, 'calendar');
    }

    /**
     * The task lists a workspace shows, by the same rule as its calendars.
     *
     * @return list<int>|null
     */
    public function taskListIds(User $user, ?Workspace $workspace = null): ?array
    {
        return $this->idsIn($user, $workspace, 'tasks');
    }

    /**
     * The workspace a calendar or task list without an account belongs to:
     * the one the user placed it in, else the first. Null for one of an
     * account, which goes where its account's groups go.
     */
    public function homeOf(User $user, Calendar|TaskList $thing): ?string
    {
        if (null !== $thing->account || null === $thing->id) {
            return null;
        }

        $all    = $this->allOf($user);
        $placed = $user->localWorkspaceOf(self::kindOf($thing), (int) $thing->id);

        foreach ($all as $workspace) {
            if ($workspace->id === $placed) {
                return $placed;
            }
        }

        return $all[0]->id;
    }

    /**
     * What an editor offers for where one without an account goes: workspace
     * name => id and the current one. Empty for one of an account, or with
     * only one workspace to be in.
     *
     * @return array{workspaces: array<string, string>, workspace: string|null}
     */
    public function placeChoices(User $user, Calendar|TaskList $thing): array
    {
        $all = $this->allOf($user);

        if (null !== $thing->account || count($all) < 2) {
            return ['workspaces' => [], 'workspace' => null];
        }

        $choices = [];

        foreach ($all as $workspace) {
            $choices[$workspace->name] = $workspace->id;
        }

        return ['workspaces' => $choices, 'workspace' => $this->homeOf($user, $thing) ?? $all[0]->id];
    }

    /**
     * Put one without an account in a workspace. The first is the default and
     * is not stored, so a list placed there follows whichever workspace comes
     * first later; an id that is no workspace is the first too.
     */
    public function place(User $user, Calendar|TaskList $thing, ?string $workspaceId): void
    {
        if (null !== $thing->account || null === $thing->id) {
            return;
        }

        $all   = $this->allOf($user);
        $known = array_map(static fn (Workspace $workspace): string => $workspace->id, $all);
        $keep  = null !== $workspaceId && $workspaceId !== $all[0]->id && in_array($workspaceId, $known, true);

        $user->setLocalWorkspaceOf(self::kindOf($thing), (int) $thing->id, $keep ? $workspaceId : null);
        $this->things = [];
    }

    /** @return list<int>|null */
    private function idsIn(User $user, ?Workspace $workspace, string $kind): ?array
    {
        $workspace ??= $this->workspaces->load($user)->active();
        $key         = $user->id . ':' . $workspace->id . ':' . $kind;

        if (array_key_exists($key, $this->things)) {
            return $this->things[$key];
        }

        $accounts = $this->resolve($user, $workspace);

        if (null === $accounts) {
            return $this->things[$key] = null;
        }

        $ids = [];

        foreach ('calendar' === $kind ? $this->calendars->findForUser($user) : $this->taskLists->findForUser($user) as $thing) {
            $in = null === $thing->account
                ? $this->homeOf($user, $thing) === $workspace->id
                : in_array((int) $thing->account->id, $accounts, true);

            if ($in) {
                $ids[] = (int) $thing->id;
            }
        }

        return $this->things[$key] = $ids;
    }

    /** @return list<Workspace> */
    private function allOf(User $user): array
    {
        return $this->all[(int) $user->id] ??= $this->workspaces->load($user)->all;
    }

    private static function kindOf(Calendar|TaskList $thing): string
    {
        return $thing instanceof Calendar ? 'calendar' : 'tasks';
    }

    /** Whether an account is one the workspace shows. */
    public function includes(User $user, Account $account): bool
    {
        $scope = $this->accountIds($user);

        return null === $scope || in_array((int) $account->id, $scope, true);
    }

    /** @return list<int>|null */
    private function resolve(User $user, Workspace $workspace): ?array
    {
        $key = $user->id . ':' . $workspace->id;

        if (false === array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = $this->resolveNow($user, $workspace);
        }

        return $this->resolved[$key];
    }

    /** @return list<int>|null */
    private function resolveNow(User $user, Workspace $workspace): ?array
    {
        if (null === $workspace->groups) {
            return null;
        }

        // The accounts of its groups (#783), together. A group that is gone
        // adds nothing; a workspace left with none of its groups shows every
        // account, as the class note says.
        $ids = [];

        foreach ($workspace->groups as $id) {
            $group = $this->groups->find($user, $id);

            if (null !== $group) {
                array_push($ids, ...$this->groups->accountIdsOf($user, $group));
            }
        }

        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return null;
        }

        // In the user's own order, which Compose's default From reads first.
        $owned = array_map(static fn (Account $account): int => (int) $account->id, $this->accounts->findForUserOrdered($user));

        return array_values(array_intersect($owned, $ids));
    }
}
