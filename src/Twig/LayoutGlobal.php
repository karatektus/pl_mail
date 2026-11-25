<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Layout\DeviceLayout;
use App\Domain\DTO\Layout\LayoutSplit;
use App\Domain\DTO\Layout\Workspace;
use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Layout\PaneKind;
use App\Entity\User\User;
use App\Service\Layout\WorkspaceStore;
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

    public function __construct(
        private readonly WorkspaceStore $store,
        private readonly Security       $security,
        private readonly RequestStack   $requests,
    ) {
    }

    public function reset(): void
    {
        $this->workspaces = null;
        $this->device     = null;
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

    /** The pane this device keeps at full width in the active workspace, if it is in it. */
    public function getMaximized(): ?PaneKind
    {
        $kind = $this->device()->maximized($this->getActive()->id);

        return null !== $kind && $this->getActive()->has($kind) ? $kind : null;
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
