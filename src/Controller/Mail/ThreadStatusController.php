<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\Mail\Message;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\ThreadSnoozeService;
use App\Service\Mail\MailPlacement;
use App\Service\Mail\MessagePurger;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The mail list's status buttons: star, archive, trash, label, snooze, read.
 *
 * Each action resolves and authorises a set of messages, hands it to
 * ThreadStatusUpdater — which owns what these mutations mean — and renders the
 * Turbo Stream that patches the row. Every route addresses either one message
 * or a whole thread through the same `{type}` segment, so the only thing that
 * differs per action below is which template and which subject it renders.
 */
#[Route('/status/{type}/{id}', name: 'app_status_', requirements: ['type' => 'message|thread', 'id' => '\d+'])]
#[IsGranted('IS_AUTHENTICATED')]
class ThreadStatusController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly ThreadStatusUpdater     $status,
        private readonly ThreadSnoozeService     $snoozeService,
        private readonly MessagePurger          $purger,
        private readonly MailPlacement          $placement,
    ) {}

    #[Route('/star', name: 'star', methods: ['POST'])]
    public function star(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);
        $message  = $messages[0];

        $this->status->star($messages);

        return $this->renderTurboStream('thread/status/_star.stream.html.twig', [
            $type => 'message' === $type ? $message : $message->thread,
        ]);
    }

    #[Route('/archive', name: 'archive', methods: ['POST'])]
    public function archive(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);

        $this->status->archive($messages);

        return $this->renderTurboStream('thread/status/_archive.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->thread,
        ]);
    }

    #[Route('/trash', name: 'trash', methods: ['POST'])]
    public function trash(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);

        $this->status->trash($messages);

        return $this->renderTurboStream('thread/status/_delete.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->thread,
        ]);
    }

    /**
     * Out of the trash or spam, back into the inbox.
     *
     * The bin could be reached and not left. An over-eager delete, or a real
     * mail the spam filter got wrong, could only be rescued by finding it and
     * attaching Inbox by hand from the label menu — which is not something
     * somebody looking at a wrongly binned mail would think to try.
     */
    #[Route('/restore', name: 'restore', methods: ['POST'])]
    public function restore(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);

        $this->status->restore($messages);

        return $this->renderTurboStream('thread/status/_delete.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->thread,
        ]);
    }

    /**
     * Delete for good. The only route in plMail that destroys mail.
     *
     * Separate from /trash rather than a flag on it, because they are different
     * promises: trash moves a conversation and can be undone by moving it back,
     * this ends it. A caller that reaches the wrong one of the two by fumbling
     * a boolean is a caller that deletes somebody's mail by accident.
     *
     * Only reachable for mail already in the trash or in spam. Not because the
     * operation could not work elsewhere, but because "delete forever" on an
     * inbox conversation is a click away from "archive" and there is no undo to
     * catch the mistake. The bin is the confirmation step the interface already
     * has; this route enforces what the templates already show.
     */
    #[Route('/purge', name: 'purge', methods: ['POST'])]
    public function purge(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);

        foreach ($messages as $message) {
            if (false === $this->placement->isDiscarded($message)) {
                throw $this->createAccessDeniedException(
                    'Only mail already in the trash or in spam can be deleted for good.',
                );
            }
        }

        // Captured before the purge: the row is about to stop existing, and the
        // stream that removes it from the list is addressed by its id.
        $target = 'message' === $type ? $messages[0]->id : $messages[0]->thread?->id;

        $this->purger->purge($messages);

        return $this->renderTurboStream('thread/status/_purge.stream.html.twig', [
            'targetId' => $target,
        ]);
    }

    /**
     * Attach or detach a custom label.
     * Expects JSON body: { "labelId": 42, "attach": true }
     */
    #[Route('/label', name: 'label', methods: ['POST'])]
    public function label(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);

        $body    = json_decode($request->getContent(), true);
        $labelId = (int) ($body['labelId'] ?? 0);
        $attach  = (true === array_key_exists('attach', $body) && true === $body['attach']);

        $label = $this->labelRepository->find($labelId);

        if (null === $label) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);

        if (true === $label->isSystem) {
            // System state is mutated via the dedicated actions only.
            throw $this->createAccessDeniedException();
        }

        $this->status->applyLabel($messages, $label, $attach);

        return $this->renderTurboStream('thread/status/_label.stream.html.twig', [
            $type   => 'message' === $type ? $messages[0] : $messages[0]->thread,
            'label'  => $label,
            'attach' => $attach,
        ]);
    }

    #[Route('/snooze', name: 'snooze', methods: ['POST'])]
    public function snooze(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);
        $thread   = $messages[0]->thread;

        // Expects JSON body: { "until": "2026-07-10T08:00:00Z" }
        // Sending no / null "until" clears the snooze.
        $body  = json_decode($request->getContent(), true);
        $until = null;

        if (true === is_array($body) && true === array_key_exists('until', $body)) {
            if (null !== $body['until']) {
                try {
                    $until = new DateTimeImmutable((string) $body['until']);
                } catch (\Exception) {
                    $until = new DateTimeImmutable('in 1 day');
                }
            }
        }

        // Through the service, not $thread->snoozedUntil: snoozing has to move the
        // Inbox label off and propagate that outward. Writing the column here
        // is what this endpoint used to do, and it left the conversation
        // sitting in the inbox — locally and at the provider — while the row
        // vanished from the list, until the sweep "woke" a thread that had
        // never left. Thread/set goes through the same service for the same
        // reason. The one deliberate difference is above: a form post gets the
        // "in 1 day" fallback on an unparseable date, where the JMAP method
        // refuses it — see ThreadSetMethod::snoozeDate().
        if (null === $until) {
            $this->snoozeService->wake($thread);
        } else {
            $this->snoozeService->snooze($thread, $until);
        }

        // No recordJmapUpdates/flush: the service records its own state
        // changes and flushes, because the label moves have to be visible to
        // the propagator jobs it queues.

        return $this->renderTurboStream('thread/status/_snooze.stream.html.twig', [
            'thread' => $thread,
        ]);
    }

    #[Route('/read', name: 'mark_read', methods: ['POST'])]
    public function markRead(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($request, $type, $id);
        $thread   = $messages[0]->thread;

        $body       = json_decode($request->getContent(), true);
        $markAsRead = (true === array_key_exists('read', $body) && true === $body['read']);

        $this->status->markRead($messages, $markAsRead);

        return $this->renderTurboStream('thread/status/_read.stream.html.twig', [
            $type        => 'message' === $type ? $messages[0] : $thread,
            'markAsRead' => $markAsRead,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The messages an action addresses, authorised before they are returned.
     *
     * The CSRF check lives here rather than in each action for the reason the
     * ownership check does: six actions all mutate, and a seventh that forgot
     * one of the two guards would look exactly like the six that did not. Every
     * caller of these routes is a Stimulus controller that already sends the
     * `ajax` token from the `csrf-token` meta tag in X-CSRF-Token — see
     * message_row, list_toolbar, thread_read, label_menu and message_actions —
     * so this pins down a token the frontend was already sending and the server
     * was throwing away. Nothing in assets/ had to change to turn it on.
     *
     * The shared `ajax` id, not one per action: that is what the meta tag mints
     * and what MailController and DevSyncController already check for the same
     * kind of fetch-driven list action. Per-action ids would be stronger — see
     * ChecksCsrf — but they buy little where all six live on one page behind one
     * token, and they cannot be adopted here without reissuing tokens per row.
     *
     * Fails closed on every path that is not a hit, because none of the three
     * used to. An id matching no row reached accountOf(), whose parameter is
     * not nullable, and died there with a TypeError — so a missing id answered
     * 500 while a real id belonging to somebody else answered 403, and the
     * difference between the two was an existence check anybody could run
     * against every id on the server. A `{type}` outside the two below left
     * $messages empty, which walked the ownership loop without entering it and
     * failed afterwards on $messages[0]; the route requirement now rejects it
     * first, and the `default` arm keeps the guarantee if that ever loosens.
     *
     * Absence and an empty thread are both 404 here rather than an empty list,
     * so the non-empty return type every caller relies on for $messages[0]
     * holds by construction.
     *
     * @return non-empty-list<Message>
     */
    private function resolveMessages(Request $request, string $type, int $id): array
    {
        $this->assertCsrf($request, 'ajax');

        $messages = match ($type) {
            // array_filter drops the null that find() returns for a missing
            // row, so the [] === check below catches it with the rest.
            'message' => array_filter([$this->messageRepository->find($id)]),
            'thread'  => $this->threadRepository->find($id)?->messages->toArray() ?? [],
            default   => [],
        };

        if ([] === $messages) {
            throw $this->createNotFoundException();
        }

        $this->assertOwnership($messages);

        return array_values($messages);
    }

    /**
     * Every message in the set, not just the first.
     *
     * A thread's messages all belong to one account today, so checking one
     * would pass the same test — but "today" is a data invariant rather than a
     * schema one, and the cost of checking all of them is a field comparison
     * per row already in memory.
     *
     * Through the voter, which reaches the owner by `Message::$account`. This
     * used to ask ThreadStatusUpdater::accountOf(), whose mailbox-then-thread
     * walk exists for Gmail-API messages that carry no mailbox and ends at the
     * NULLABLE `$message->thread` — an ownership check that could fatal on a
     * message with neither. The direct link is required by the schema.
     *
     * @param iterable<Message> $messages
     */
    private function assertOwnership(iterable $messages): void
    {
        foreach ($messages as $message) {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        }
    }
}
