<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Entity\Mail\Message;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\ThreadSnoozeService;
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
#[Route('/status/{type}/{id}', name: 'app_status_')]
#[IsGranted('IS_AUTHENTICATED')]
class ThreadStatusController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly ThreadStatusUpdater     $status,
        private readonly ThreadSnoozeService     $snoozeService,
    ) {}

    #[Route('/star', name: 'star', methods: ['POST'])]
    public function star(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);
        $message  = $messages[0];

        $this->status->star($messages);

        return $this->renderTurboStream('thread/status/_star.stream.html.twig', [
            $type => 'message' === $type ? $message : $message->thread,
        ]);
    }

    #[Route('/archive', name: 'archive', methods: ['POST'])]
    public function archive(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);

        $this->status->archive($messages);

        return $this->renderTurboStream('thread/status/_archive.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->thread,
        ]);
    }

    #[Route('/trash', name: 'trash', methods: ['POST'])]
    public function trash(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);

        $this->status->trash($messages);

        return $this->renderTurboStream('thread/status/_delete.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->thread,
        ]);
    }

    /**
     * Attach or detach a custom label.
     * Expects JSON body: { "labelId": 42, "attach": true }
     */
    #[Route('/label', name: 'label', methods: ['POST'])]
    public function label(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);

        $body    = json_decode($request->getContent(), true);
        $labelId = (int) ($body['labelId'] ?? 0);
        $attach  = (true === array_key_exists('attach', $body) && true === $body['attach']);

        $label = $this->labelRepository->find($labelId);

        if (null === $label || $label->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

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
        $messages = $this->resolveMessages($type, $id);
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
        $messages = $this->resolveMessages($type, $id);
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
     * @return Message[]
     */
    private function resolveMessages(string $type, int $id): array
    {
        $messages = [];

        if ('message' === $type) {
            $messages = [$this->messageRepository->find($id)];
        }

        if ('thread' === $type) {
            $messages = $this->threadRepository->find($id)->messages->toArray();
        }

        $this->assertOwnership($messages);

        return array_values($messages);
    }

    /**
     * @param iterable<Message> $messages
     */
    private function assertOwnership(iterable $messages): void
    {
        foreach ($messages as $message) {
            if ($this->status->accountOf($message)->usr !== $this->getUser()) {
                throw $this->createAccessDeniedException();
            }
        }
    }

    private function renderTurboStream(string $template, array $params = []): Response
    {
        return $this->render($template, $params, new Response(
            headers: ['Content-Type' => 'text/vnd.turbo-stream.html'],
        ));
    }
}
