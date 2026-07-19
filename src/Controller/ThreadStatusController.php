<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\LabelRole;
use App\Domain\Enum\MessageFlag;
use App\Entity\Label;
use App\Entity\Message;
use App\Repository\LabelRepository;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Status actions are label mutations first (DB = source of truth), then
 * propagated to the provider asynchronously via LabelChangePropagator:
 * IMAP as flag/move operations, Gmail as messages.batchModify.
 *
 * Archive is the removal of the Inbox label. Trash is Trash added and Inbox
 * removed. For plain-IMAP messages the local mailbox pointer is re-pointed
 * optimistically so the sync layer stays coherent.
 */
#[Route('/status/{type}/{id}', name: 'app_status_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ThreadStatusController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly LabelResolver           $labelResolver,
        private readonly LabelChangePropagator   $propagator,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
    ) {}

    #[Route('/star', name: 'star', methods: ['POST'])]
    public function star(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);

        $message = $messages[0];
        $starred = null === $message->getStarredAt();

        if (true === $starred) {
            $message
                ->addFlag(MessageFlag::FLAGGED)
                ->setStarredAt(new DateTimeImmutable());
            $message->getThread()->setStarredAt(new DateTimeImmutable());
        } else {
            $message
                ->removeFlag(MessageFlag::FLAGGED)
                ->setStarredAt(null);
            $message->getThread()->setStarredAt(null);
        }

        $this->propagator->star($messages, $starred);
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_star.stream.html.twig', [
            $type => 'message' === $type ? $message : $message->getThread(),
        ]);
    }

    #[Route('/archive', name: 'archive', methods: ['POST'])]
    public function archive(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);
        $account  = $this->accountOf($messages[0]);

        $inboxLabel = $this->labelRepository->findOneByRoleForAccount(LabelRole::Inbox, $account);

        // Propagate BEFORE re-pointing mailboxes so the IMAP job captures
        // the correct source folders.
        $this->propagator->archive($messages);

        $archiveMailbox = $this->mailboxRepository->findOneBy([
            'account' => $account,
            'label'   => $this->labelResolver->systemLabel(LabelRole::Archive, $account),
        ]);

        foreach ($messages as $message) {
            if (null !== $inboxLabel) {
                $message->removeLabel($inboxLabel);
            }

            // Plain-IMAP: the message physically moves to the Archive folder.
            if (null !== $message->getImapUid() && null !== $archiveMailbox) {
                $message->setMailbox($archiveMailbox);
            }
        }

        $this->threadLabelSynchronizer->sync($messages[0]->getThread());
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_archive.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->getThread(),
        ]);
    }

    #[Route('/trash', name: 'trash', methods: ['POST'])]
    public function trash(string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);
        $account  = $this->accountOf($messages[0]);

        $inboxLabel = $this->labelRepository->findOneByRoleForAccount(LabelRole::Inbox, $account);
        $trashLabel = $this->labelResolver->systemLabel(LabelRole::Trash, $account);

        $this->propagator->trash($messages);

        $trashMailbox = $this->mailboxRepository->findOneBy([
            'account' => $account,
            'label'   => $trashLabel,
        ]);

        foreach ($messages as $message) {
            $message->addLabel($trashLabel);

            if (null !== $inboxLabel) {
                $message->removeLabel($inboxLabel);
            }

            if (null !== $message->getImapUid() && null !== $trashMailbox) {
                $message->setMailbox($trashMailbox);
            }
        }

        $this->threadLabelSynchronizer->sync($messages[0]->getThread());
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_delete.stream.html.twig', [
            $type => 'message' === $type ? $messages[0] : $messages[0]->getThread(),
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

        if (null === $label || $label->account?->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (true === $label->isSystem) {
            // System state is mutated via the dedicated actions only.
            throw $this->createAccessDeniedException();
        }

        if (true === $attach) {
            foreach ($messages as $message) {
                $message->addLabel($label);
            }

            $this->propagator->attachLabel($messages, $label);
        } else {
            foreach ($messages as $message) {
                $message->removeLabel($label);
            }

            // Handles the IMAP location-label replacement (physical move)
            // internally; must run before flush.
            $this->propagator->detachLabel($messages, $label);
        }

        $this->threadLabelSynchronizer->sync($messages[0]->getThread());
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_label.stream.html.twig', [
            $type   => 'message' === $type ? $messages[0] : $messages[0]->getThread(),
            'label'  => $label,
            'attach' => $attach,
        ]);
    }

    #[Route('/snooze', name: 'snooze', methods: ['POST'])]
    public function snooze(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);
        $thread   = $messages[0]->getThread();

        // Expects JSON body: { "until": "2026-07-10T08:00:00Z" }
        // Sending no / null "until" clears the snooze.
        $body  = json_decode($request->getContent(), true);
        $until = null;

        if (true === array_key_exists('until', $body)) {
            if (null !== $body['until']) {
                try {
                    $until = new DateTimeImmutable($body['until']);
                } catch (\Exception $e) {
                    $until = new DateTimeImmutable('in 1 day');
                }
            }
        }

        $thread->setSnoozedUntil($until);
        $this->em->flush();

        return $this->renderTurboStream('thread/status/_snooze.stream.html.twig', [
            'thread' => $thread,
        ]);
    }

    #[Route('/read', name: 'mark_read', methods: ['POST'])]
    public function markRead(Request $request, string $type, int $id): Response
    {
        $messages = $this->resolveMessages($type, $id);
        $thread   = $messages[0]->getThread();

        $body       = json_decode($request->getContent(), true);
        $markAsRead = (true === array_key_exists('read', $body) && true === $body['read']);
        $unread     = 0;

        foreach ($messages as $message) {
            if (true === $markAsRead) {
                $message
                    ->addFlag(MessageFlag::SEEN)
                    ->setSeenAt(new DateTimeImmutable());
            } else {
                $message
                    ->removeFlag(MessageFlag::SEEN)
                    ->setSeenAt(null);
                $unread++;
            }
        }

        $thread->setUnreadCount($unread);
        $this->propagator->markRead($messages, $markAsRead);
        $this->em->flush();

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
            $messages = $this->threadRepository->find($id)->getMessages()->toArray();
        }

        $this->assertOwnership($messages);

        return array_values($messages);
    }

    private function accountOf(Message $message): \App\Entity\Account
    {
        $mailbox = $message->getMailbox();

        if (null !== $mailbox) {
            return $mailbox->getAccount();
        }

        // Gmail-API messages have no mailbox — the thread carries the account.
        return $message->getThread()->getAccount();
    }

    /**
     * @param iterable<Message> $messages
     */
    private function assertOwnership(iterable $messages): void
    {
        foreach ($messages as $message) {
            if ($this->accountOf($message)->getUsr() !== $this->getUser()) {
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
