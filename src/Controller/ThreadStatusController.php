<?php

namespace App\Controller;

use App\Entity\MessageThread;
use App\Entity\User;
use App\Repository\MessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/thread/{thread}/status', name: 'app_thread_status_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ThreadStatusController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRepository $messageRepository,
    ) {}

    #[Route('/star', name: 'star', methods: ['POST'])]
    public function star(MessageThread $thread): Response
    {
        $this->assertOwnership($thread);

        $thread->setStarredAt(
            $thread->getStarredAt() === null ? new DateTimeImmutable() : null
        );

        $this->em->flush();

        return $this->renderTurboStream('thread/status/_star.stream.html.twig', [
            'thread' => $thread,
        ]);
    }

    #[Route('/archive', name: 'archive', methods: ['POST'])]
    public function archive(MessageThread $thread): Response
    {
        $this->assertOwnership($thread);

        $thread->setArchivedAt(
            $thread->isArchived() ? null : new DateTimeImmutable()
        );
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_archive.stream.html.twig', [
            'thread' => $thread,
        ]);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(MessageThread $thread): Response
    {
        $this->assertOwnership($thread);
        $threadId = $thread->getId();

        $this->em->remove($thread);
        $this->em->flush();

        // Remove the row from the list entirely.
        return $this->renderTurboStream('thread/status/_delete.stream.html.twig', [
            'thread_id' => $threadId,
        ]);
    }

    #[Route('/snooze', name: 'snooze', methods: ['POST'])]
    public function snooze(MessageThread $thread, Request $request): Response
    {
        $this->assertOwnership($thread);

        // Expects JSON body: { "until": "2026-07-10T08:00:00Z" }
        // Sending no / null "until" clears the snooze.
        $body = json_decode($request->getContent(), true);
        $until = null;

        if (!empty($body['until'])) {
            $until = new DateTimeImmutable($body['until']);
        }

        $thread->setSnoozedUntil($until);
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_snooze.stream.html.twig', [
            'thread' => $thread,
        ]);
    }

    #[Route('/read', name: 'mark_read', methods: ['POST'])]
    public function markRead(MessageThread $thread, Request $request): Response
    {
        $this->assertOwnership($thread);

        $body = json_decode($request->getContent(), true);
        // true → mark as read, false → mark as unread
        $markAsRead = (bool) ($body['read'] ?? true);

        $messages = $this->messageRepository->findBy(['thread' => $thread]);
        $unread = 0;

        foreach ($messages as $message) {
            $flags = $message->getFlags() ?? [];

            if ($markAsRead) {
                $flags = array_values(array_filter($flags, fn($f) => $f !== 'unread'));
            } else {
                if (!in_array('unread', $flags, true)) {
                    $flags[] = 'unread';
                }
                $unread++;
            }

            $message->setFlags($flags ?: null);
        }

        $thread->setUnreadCount($markAsRead ? 0 : $unread);
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_read.stream.html.twig', [
            'thread'   => $thread,
            'markAsRead' => $markAsRead,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function assertOwnership(MessageThread $thread): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($thread->getAccount()->getUsr() !== $user) {
            throw $this->createAccessDeniedException('This thread does not belong to you.');
        }
    }

    private function renderTurboStream(string $template, array $params = []): Response
    {
        return $this->render($template, $params, new Response(
            headers: ['Content-Type' => 'text/vnd.turbo-stream.html'],
        ));
    }
}
