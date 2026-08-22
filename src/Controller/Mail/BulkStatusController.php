<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\ListViewResolver;
use App\Service\Mail\ThreadStatusUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk mail actions — one request for a whole selection.
 *
 * Its own controller rather than another route on ThreadStatusController,
 * because that one is prefixed `/status/{type}/{id}` and every route on it is
 * about ONE conversation named in the path. A bulk action is about a set named
 * in the body, and hanging it off that prefix would have produced
 * `/status/thread/17/bulk/archive` — a URL that names a conversation it does
 * not act on.
 */
#[Route('/status/bulk', name: 'app_status_bulk_')]
#[IsGranted('ROLE_USER')]
final class BulkStatusController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly MessageThreadRepository $threadRepository,
        private readonly ThreadStatusUpdater     $status,
        private readonly ListViewResolver        $views,
    ) {
    }

    /**
     * One request for a whole selection, and for a whole view.
     *
     * The toolbar used to post once per conversation, in parallel. That is fine
     * for the fifty rows a page can hold and impossible for what was asked for
     * — "select everything in this folder" over a bin holding two hundred would
     * have opened two hundred connections, and a real mailbox is worse.
     *
     * It also could not answer the other half of what the list needs. Each of
     * those responses redrew its own row, so the pager kept saying "1–5 of 5"
     * over four rows and an emptied list never showed its empty state: nothing
     * in a per-row stream knows what the list as a whole now looks like. This
     * answers with the list frame, once, which is the only thing that does.
     *
     * @see ListViewResolver for why the view arrives as a named scope rather
     *      than as the URL the user is on.
     */
    #[Route('/{action}', name: 'run', methods: ['POST'], requirements: ['action' => 'archive|trash|read|restore'])]
    public function bulk(Request $request, string $action): Response
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        $threads = true === ($body['all'] ?? false)
            ? $this->views->threadsIn(
                $user,
                (string) ($body['scope'] ?? ''),
                (string) ($body['value'] ?? ''),
                true === ($body['unreadOnly'] ?? false),
            )
            : $this->threadRepository->findBy(['id' => array_map(intval(...), (array) ($body['ids'] ?? []))]);

        // Grouped by account, and that is not an optimisation.
        // ThreadStatusUpdater resolves the destination label and its IMAP
        // folder from the FIRST message's account — which is right for every
        // caller that names one conversation, and wrong for a selection
        // spanning two mailboxes: everything would be filed into the first
        // account's Archive. One call per account keeps each resolution
        // correct, and there are rarely more than three.
        $byAccount = [];

        foreach ($threads as $thread) {
            // Every thread, not just the first: the ids arrive from a browser
            // and a selection can be edited before it is posted.
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $thread);

            foreach ($thread->messages as $message) {
                $byAccount[(int) $message->account?->id][] = $message;
            }
        }

        if ([] === $byAccount) {
            return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
                'count'   => 0,
                'threads' => [],
                'leaves'  => false,
            ]);
        }

        foreach ($byAccount as $messages) {
            match ($action) {
                'archive' => $this->status->archive($messages),
                'trash'   => $this->status->trash($messages),
                'restore' => $this->status->restore($messages),
                'read'    => $this->status->markRead($messages, true === ($body['read'] ?? true)),
            // Unreachable: the route requirement above is the allow-list, so a
            // fifth action cannot arrive here without being added there too.
            // Stated rather than assumed, because the day someone widens the
            // requirement and forgets this arm, a silent no-op is the worst
            // possible answer for an action that says it deleted things.
                default   => throw $this->createNotFoundException(sprintf('Unknown bulk action "%s".', $action)),
            };
        }

        return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
            'count'   => count($threads),
            'threads' => $threads,
            // Whether the conversation is still in the list it was acted on
            // from. Archive, trash and restore all move it somewhere else;
            // marking read leaves it exactly where it was and only changes how
            // it draws.
            'leaves'  => 'read' !== $action,
        ]);
    }
}
