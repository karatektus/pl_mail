<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Layout\DeviceLayout;
use App\Domain\DTO\Layout\LayoutSplit;
use App\Domain\DTO\Layout\Sidebar;
use App\Domain\DTO\Layout\Workspace;
use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Layout\PaneKind;
use App\Entity\User\User;
use App\Service\Layout\SidebarRows;
use App\Service\Layout\SidebarStore;
use App\Service\Layout\WorkspaceNews;
use App\Service\Layout\WorkspaceStore;
use App\Service\Mail\UnifiedInboxes;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The `layout` global: the signed-in user's workspaces and this device's
 * sizes for them (#755).
 *
 * A global because two templates no controller sits behind both need it: the
 * mailbox shell draws the active workspace's panes, and the top bar lists
 * every workspace. The shell is extended by a dozen page templates, each from
 * a different controller.
 *
 * Memoised per request, reset between requests for the worker-mode reason
 * ClockGlobal gives.
 */
class LayoutGlobal implements ResetInterface
{
    private ?Workspaces $workspaces = null;

    private ?DeviceLayout $device = null;

    /** @var array{soon: bool, next: \DateTimeImmutable}|null see pulse() */
    private ?array $pulse = null;

    public function __construct(
        private readonly WorkspaceStore $store,
        private readonly Security       $security,
        private readonly RequestStack   $requests,
        private readonly SidebarStore   $sidebars,
        private readonly SidebarRows    $sidebarRows,
        private readonly WorkspaceNews  $news,
        private readonly UnifiedInboxes $groups,
    ) {
    }

    /**
     * The sidebar the workspace on screen shows (#783): 'mail' for the
     * built-in one, a Sidebar of the user's own, or null for none. An id that
     * no longer names a sidebar falls back to the Mail sidebar rather than to
     * none — a missing sidebar should not take the folders away.
     */
    public function getSidebar(): Sidebar|string|null
    {
        $id = $this->getActive()->sidebar;

        if (null === $id || Workspace::SIDEBAR_MAIL === $id) {
            return $id;
        }

        return $this->sidebars->find($this->user(), $id) ?? Workspace::SIDEBAR_MAIL;
    }

    /** @return list<array<string, mixed>> the rows of the workspace's own sidebar; see SidebarRows */
    public function getSidebarRows(): array
    {
        $sidebar = $this->getSidebar();

        return $sidebar instanceof Sidebar ? $this->sidebarRows->rows($this->user(), $sidebar) : [];
    }

    /**
     * Each account group's accounts, group id => account ids, for the page to
     * tell which workspace shows an account (#783).
     *
     * @return array<int, list<int>>
     */
    public function getGroupAccounts(): array
    {
        $user = $this->user();
        $out  = [];

        foreach ($this->groups->all($user) as $group) {
            $out[(int) $group->id] = $this->groups->accountIdsOf($user, $group);
        }

        return $out;
    }

    /** @return array<string, Sidebar> the user's own sidebars, for the pickers and the editor */
    public function getSidebars(): array
    {
        return $this->sidebars->load($this->user());
    }

    /** @return array<string, array{count: int, soon: bool}> the other workspaces' "new" marks */
    public function getNews(): array
    {
        return $this->news->forTabs($this->user(), $this->getWorkspaces());
    }

    /** When the page should ask for the counts again if nothing is pushed (unix seconds). */
    public function getNewsNext(): int
    {
        return $this->pulse()['next']->getTimestamp();
    }

    /** Whether anything is about to happen, in any account: the dot on Happening soon. */
    public function getSoon(): bool
    {
        return $this->pulse()['soon'];
    }

    /** @return array{soon: bool, next: \DateTimeImmutable} read once for both getters */
    private function pulse(): array
    {
        return $this->pulse ??= $this->news->pulse($this->user());
    }

    private function user(): User
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            throw new LogicException('The layout global is only drawn for a signed-in user.');
        }

        return $user;
    }

    public function reset(): void
    {
        $this->workspaces = null;
        $this->device     = null;
        $this->pulse      = null;
    }

    public function getWorkspaces(): Workspaces
    {
        if (null === $this->workspaces) {
            $user = $this->security->getUser();

            if (false === $user instanceof User) {
                throw new LogicException('The layout global is only drawn for a signed-in user.');
            }

            $this->workspaces = $this->store->load($user);
        }

        return $this->workspaces;
    }

    public function getActive(): Workspace
    {
        return $this->getWorkspaces()->active();
    }

    /** The whole document, for the page's controller to edit. */
    public function getDocument(): string
    {
        return json_encode($this->getWorkspaces()->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * The shares a split of the ACTIVE workspace is drawn at on this device.
     *
     * @return list<float>
     */
    public function sizes(LayoutSplit $split): array
    {
        return $this->device()->sizes($this->getActive()->id, $split);
    }

    /** The key of the pane this device keeps at full width in the active workspace, if it is in it. */
    public function getMaximized(): ?string
    {
        $key = $this->device()->maximized($this->getActive()->id);

        return null !== $key && $this->getActive()->hasKey($key) ? $key : null;
    }

    /** @return list<PaneKind> */
    public function getKinds(): array
    {
        return PaneKind::cases();
    }

    private function device(): DeviceLayout
    {
        return $this->device ??= DeviceLayout::fromCookie(
            $this->requests->getCurrentRequest()?->cookies->get(DeviceLayout::COOKIE),
        );
    }
}
