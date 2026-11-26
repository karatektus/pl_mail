<?php

declare(strict_types=1);

namespace App\Controller\Layout;

use App\Entity\User\User;
use App\Service\Layout\WorkspacePin;
use App\Service\Layout\WorkspaceScope;
use App\Service\Layout\WorkspaceStore;
use App\Service\Mail\NewMailMarkers;
use App\Service\Mail\SidebarCounts;
use App\Twig\AccountsGlobal;
use App\Twig\CalendarGlobal;
use App\Twig\LayoutGlobal;
use Dom\HTMLDocument;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Everything a workspace switch draws again, in one Turbo Stream (#783).
 *
 * A switch to other account groups or another sidebar changes the sidebar,
 * the mail list and the calendar and tasks panes. Reloaded frame by frame,
 * each changed when its own answer came, after the switch's save — the old
 * mail and the old sidebar stayed up, then changed one after another. Here
 * the page names the frames it will show and where each points, and gets back
 * one stream that fills them all; Turbo applies it in one go.
 *
 * Nothing here renders a pane: each frame is drawn by the route that always
 * draws it, as a sub-request with the frame's Turbo-Frame header, for the
 * workspace in the URL (WorkspacePin) rather than the saved one, so nothing
 * waits for the save. Only the routes below are followed — a sub-request
 * skips the firewall's access rules, so the page cannot name just any URL.
 *
 * A GET that writes nothing of its own, so the page can ask ahead, when the
 * pointer rests on a tab. The routes it follows keep what their URL says
 * (a calendar pane's `?scope=`, its view), as they would loading the frame;
 * the page only names where the frame already points, so that is the same
 * value written again.
 */
#[IsGranted('ROLE_USER')]
final class WorkspaceRedrawController extends AbstractController
{
    /** The routes whose frames a workspace narrows, and the only ones followed. */
    private const array ROUTES = [
        'app_layout_sidebar',
        'app_mail_inbox', 'app_mail_label', 'app_mail_label_path', 'app_mail_account', 'app_mail_all',
        'app_mail_account_all', 'app_mail_starred', 'app_mail_unread', 'app_mail_sent', 'app_mail_drafts',
        'app_mail_trash', 'app_mail_spam', 'app_mail_archive', 'app_mail_snoozed', 'app_mail_search',
        'app_calendar_pane', 'app_calendar_view',
        'app_tasks_index',
    ];

    /** More than one page could ever show. */
    private const int MAX_FRAMES = 12;

    /** The list frame is replaced whole: its own attributes say which view it holds. */
    private const string LIST_FRAME = 'inbox-list-frame';

    public function __construct(
        private readonly WorkspaceStore      $store,
        private readonly WorkspacePin        $pin,
        private readonly WorkspaceScope      $scope,
        private readonly RouterInterface     $router,
        private readonly HttpKernelInterface $kernel,
        NewMailMarkers                       $newMail,
        SidebarCounts                        $counts,
        LayoutGlobal                         $layout,
        AccountsGlobal                       $accounts,
        CalendarGlobal                       $calendar,
    ) {
        $this->memos = [$scope, $newMail, $counts, $layout, $accounts, $calendar];
    }

    /**
     * What a frame's own request would start without: the per-request memos
     * of the drawing. A page loads each frame in a request of its own; here
     * they share one, and one frame's narrowing (the list's tab scope, say)
     * must not carry into the next.
     *
     * @var list<ResetInterface>
     */
    private array $memos;

    /** `f[]` is "frame-id url", one per frame, as layout--workspace#redrawTargets() lists them. */
    #[Route('/layout/workspace/{id}/redraw', name: 'app_layout_workspace_redraw', requirements: ['id' => '[A-Za-z0-9_-]{1,40}'], methods: ['GET'])]
    public function redraw(Request $request, string $id): Response
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $known = array_map(static fn ($workspace): string => $workspace->id, $this->store->load($user)->all);

        if (false === in_array($id, $known, true)) {
            throw $this->createNotFoundException();
        }

        $this->pin->pin($id);

        $streams = '';
        $frames  = $request->query->all()['f'] ?? [];

        foreach (array_slice(is_array($frames) ? $frames : [], 0, self::MAX_FRAMES) as $entry) {
            if (false === is_string($entry) || 1 !== preg_match('~^([A-Za-z0-9_-]{1,80}) (/(?!/)\S{0,2000})$~', $entry, $match)) {
                continue;
            }

            $streams .= $this->frameStream($request, $match[1], $match[2]);
        }

        return new Response($streams, Response::HTTP_OK, [
            'Content-Type'  => 'text/vnd.turbo-stream.html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** One frame's stream action, or nothing for a URL not followed or a frame not found. */
    private function frameStream(Request $request, string $frame, string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        try {
            $route = $this->router->match($path)['_route'] ?? null;
        } catch (RoutingException) {
            return '';
        }

        if (false === in_array($route, self::ROUTES, true)) {
            return '';
        }

        foreach ($this->memos as $memo) {
            $memo->reset();
        }

        $sub = Request::create($url, 'GET', [], $request->cookies->all(), [], $request->server->all());
        $sub->headers->set('Turbo-Frame', $frame);
        $sub->headers->set('Accept', 'text/html');

        if ($request->hasSession()) {
            $sub->setSession($request->getSession());
        }

        try {
            $response = $this->kernel->handle($sub, HttpKernelInterface::SUB_REQUEST, false);
        } catch (Throwable) {
            return '';
        }

        if (false === $response->isSuccessful()) {
            return '';
        }

        $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
        $element  = $document->getElementById($frame);

        if (null === $element) {
            return '';
        }

        // The list frame's attributes say which view and scope it holds, so
        // it is replaced; a pane's frame keeps its own src and loading, and
        // only what is in it changes.
        $replace = self::LIST_FRAME === $frame;
        $content = $replace ? $document->saveHtml($element) : $element->innerHTML;

        return sprintf(
            '<turbo-stream action="%s" target="%s"><template>%s</template></turbo-stream>',
            $replace ? 'replace' : 'update',
            htmlspecialchars($frame, ENT_QUOTES),
            $content,
        );
    }
}
