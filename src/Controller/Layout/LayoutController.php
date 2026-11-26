<?php

declare(strict_types=1);

namespace App\Controller\Layout;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Layout\Sidebar;
use App\Domain\DTO\Layout\Workspace;
use App\Domain\DTO\Layout\Workspaces;
use App\Entity\User\User;
use App\Service\Layout\SidebarRows;
use App\Service\Layout\SidebarStore;
use App\Service\Layout\WorkspaceNews;
use App\Service\Layout\WorkspaceStore;
use App\Service\Mail\UnifiedInboxes;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where the page saves the user's workspaces (#755).
 *
 * One endpoint for every change — a split, a move, a rename, a switch, a new
 * workspace — because the page holds the whole document and every change is
 * an edit to it. Posting the whole thing also means a lost write is repaired
 * by the next one, rather than leaving the server one step behind forever.
 *
 * Divider positions are not posted here: they are the device's and live in
 * its cookie (DeviceLayout). What arrives here is only the arrangement, and
 * the proportions a new device should start from.
 */
#[Route('/layout', name: 'app_layout_')]
#[IsGranted('ROLE_USER')]
final class LayoutController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly WorkspaceStore         $store,
        private readonly EntityManagerInterface $em,
        private readonly SidebarStore           $sidebars,
        private readonly WorkspaceNews          $news,
        private readonly SidebarRows            $sidebarRows,
        private readonly UnifiedInboxes         $groups,
    ) {
    }

    /**
     * The workspace on screen's own settings (#783): its accounts, its
     * sidebar and where that sidebar's links open — and the editor for the
     * user's sidebars, since picking a sidebar and shaping it are one task.
     * Drawn into the modal frame.
     */
    #[Route('/workspace/settings', name: 'workspace_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $user       = $this->currentUser();
        $workspaces = $this->store->load($user);
        $workspace  = $workspaces->active();

        $panes = [];
        $seen  = [];

        foreach ($workspace->root->panes() as $pane) {
            $kind = $pane->kind->value;
            $seen[$kind] = ($seen[$kind] ?? 0) + 1;
            $panes[$kind][] = ['key' => $pane->key(), 'n' => $seen[$kind]];
        }

        return $this->render('layout/_workspace_settings.html.twig', [
            'workspace' => $workspace,
            'groups'    => $this->groups->all($user),
            'sidebars'  => array_values(array_map(static fn (Sidebar $sidebar): array => $sidebar->toArray(), $this->sidebars->load($user))),
            'catalog'   => $this->sidebarRows->catalog($user),
            'panes'     => $panes,
            // Which workspaces use each sidebar: editing one changes it in all
            // of them, and the editor says so.
            'usedBy'    => $this->sidebarUsers($workspaces),
        ]);
    }

    /**
     * Saves what the dialog holds that is not the workspace's own: the
     * user's sidebars, whole, and the Mail sidebar's clock (#783). The
     * workspace's groups, sidebar and targets go back into the edit draft.
     */
    #[Route('/workspace/settings', name: 'workspace_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'layout_workspaces');

        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(['error' => 'invalid'], 422);
        }

        if (false === is_array($data) || false === is_array($data['sidebars'] ?? null) || false === array_is_list($data['sidebars']) || count($data['sidebars']) > SidebarStore::MAX) {
            return $this->json(['error' => 'invalid'], 422);
        }

        $sidebars = [];

        foreach ($data['sidebars'] as $item) {
            $sidebar = Sidebar::fromArray($item);

            if (null === $sidebar || isset($sidebars[$sidebar->id])) {
                return $this->json(['error' => 'invalid'], 422);
            }

            $sidebars[$sidebar->id] = $sidebar;
        }

        $user = $this->currentUser();

        // The workspace's own settings are part of the edit draft in the page
        // and go with Save workspace. What is shared — the sidebars, the Mail
        // sidebar's clock — is saved here.
        if (is_bool($data['mailClock'] ?? null)) {
            $user->mailSidebarClock = $data['mailClock'];
        }

        $this->sidebars->save($user, $sidebars);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Replace the user's workspaces with the posted document.
     *
     * JSON in the body and the token in a header: the document is nested, and
     * a form encoding of a tree is a format nobody would choose. A document
     * that does not parse is refused with 422 and nothing is written — the
     * page keeps what it has, and the stored copy stays the last good one.
     */
    #[Route('/workspaces', name: 'workspaces', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'layout_workspaces');

        try {
            $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(['error' => 'invalid'], 422);
        }

        $workspaces = Workspaces::fromArray($data);

        if (null === $workspaces) {
            return $this->json(['error' => 'invalid'], 422);
        }

        /** @var User $user */
        $user = $this->currentUser();

        // A switch: the workspace left was on screen until now, and the one
        // switched to is from now (#783). Both stop counting as new.
        $before = $this->store->load($user)->active()->id;

        if ($before !== $workspaces->active()->id) {
            $this->news->touch($user, $before, $workspaces->active()->id);
        }

        $this->news->prune($user, $workspaces);
        $this->store->save($user, $workspaces);
        $this->em->flush();

        return $this->json($workspaces->toArray());
    }

    /**
     * The sidebar of the workspace on screen, as its frame (#783): what a
     * switch to other account groups or another sidebar reloads in place of
     * the page. `?drawer=1` for the phone's drawer.
     */
    #[Route('/sidebar', name: 'sidebar', methods: ['GET'])]
    public function sidebar(Request $request): Response
    {
        return $this->render('_layout/_sidebar_frame.html.twig', [
            'drawer' => $request->query->getBoolean('drawer'),
        ]);
    }

    /**
     * The other workspaces' "new" counts (#783), asked for by the page when a
     * Mercure nudge says mail or a calendar changed, when it comes back into
     * view, after a switch, and at the next moment the clock alone changes
     * them. A POST because it also says the workspace on screen is being
     * looked at, which is what keeps its own count at zero.
     */
    #[Route('/news', name: 'news', methods: ['POST'])]
    public function news(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'layout_workspaces');

        $user       = $this->currentUser();
        $workspaces = $this->store->load($user);

        if ($this->news->touch($user, $workspaces->active()->id)) {
            $this->em->flush();
        }

        $pulse = $this->news->pulse($user);

        return $this->json([
            'tabs' => (object) $this->news->forTabs($user, $workspaces),
            'soon' => $pulse['soon'],
            // When to ask again if nothing is pushed meanwhile (unix seconds).
            'next' => $pulse['next']->getTimestamp(),
        ]);
    }

    /** @return array<string, list<string>> sidebar id => names of the workspaces that show it */
    private function sidebarUsers(Workspaces $workspaces): array
    {
        $out = [];

        foreach ($workspaces->all as $workspace) {
            if (null !== $workspace->sidebar) {
                $out[$workspace->sidebar][] = $workspace->name;
            }
        }

        return $out;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
