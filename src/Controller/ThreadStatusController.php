<?php

namespace App\Controller;

use App\Domain\Enum\MailboxSpecialUse;
use App\Domain\Enum\MessageFlag;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Entity\User;
use App\Message\ApplyImapFlagsMessage;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function Sodium\add;

#[Route('/status/{type}/{id}', name: 'app_status_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ThreadStatusController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly MessageBusInterface     $bus,
        private readonly MailboxRepository       $mailboxRepository,
    )
    {
    }

    #[Route('/star', name: 'star', methods: ['POST'])]
    public function star(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);

        $message = $messages[0];

        $action = 'flag';
        if ($message->getStarredAt() === null) {
            $message
                ->addFlag(MessageFlag::FLAGGED)
                ->setStarredAt(new DateTimeImmutable());
            $message->getThread()->setStarredAt(new DateTimeImmutable());
        } else {
            $action = 'unflag';
            $message->removeFlag(MessageFlag::FLAGGED)
                ->setStarredAt(null);
            $message->getThread()->setStarredAt(null);
        }

        $this->em->flush();

        $this->dispatchImapAction($messages, $action);

        return $this->renderTurboStream('thread/status/_star.stream.html.twig', [
            $type => 'message' === $type ? $message : $message->getThread(),
        ]);
    }

    #[Route('/archive', name: 'archive', methods: ['POST'])]
    public function archive(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);

        $archiveMailbox = $this->mailboxRepository->findArchiveMailboxForAccount($messages[0]->getMailbox()->getAccount());
        if(null === $archiveMailbox){
            return $this->renderTurboStream('_toasts/generic.html.twig', ['type' => 'error', 'message' => 'toast.error.no_archive_mailbox']);
        }
//        $thread->setArchivedAt(
//            $thread->isArchived() ? null : new DateTimeImmutable()
//        );
        $this->em->flush();
        //move to archive
        return $this->renderTurboStream('thread/status/_archive.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->getThread(),
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

        $markAsRead = (true === array_key_exists('read', $body) && true === $body['read']);

        $unread = 0;
        foreach ($thread->getMessages() as $message) {
            if (true === $markAsRead) {
                $message->addFlag(MessageFlag::SEEN);
            } else {
                $message->removeFlag(MessageFlag::SEEN);
                $unread++;
            }
        }

        $thread->setUnreadCount($unread);

        $this->em->flush();

        return $this->renderTurboStream('thread/status/_read.stream.html.twig', [
            'thread' => $thread,
            'markAsRead' => $markAsRead,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return iterable<Message>
     */
    private function resolveMessages(string $type, int $id): iterable
    {
        $messages = [];

        if ('message' === $type) {
            $messages = [$this->messageRepository->find($id)];
        }

        if ('thread' === $type) {
            $messages = $this->threadRepository->find($id)->getMessages();
        }

        $this->assertOwnership($messages);

        return $messages;
    }

    /**
     * @param iterable<Message> $messages
     */
    private function dispatchImapAction(iterable $messages, string $action): void
    {
        $ids = [];
        foreach ($messages as $message) {
            if(null === $message->getImapUid()){
                continue;
            }

            $ids[] = $message->getId();
        }

        if (count($ids) === 0) {
            return;
        }

        $this->bus->dispatch(new ApplyImapFlagsMessage($ids, $action));
    }

    /**
     * @param iterable<Message> $messages
     */
    private function assertOwnership(iterable $messages): void
    {
        foreach ($messages as $message) {
            if ($message->getMailbox()->getAccount()->getUsr() !== $this->getUser()) {
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
