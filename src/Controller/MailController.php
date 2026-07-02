<?php

namespace App\Controller;

use App\Domain\Enum\MessageTab;
use App\Entity\Account;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mail', name: 'app_mail_')]
final class MailController extends AbstractController
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly MessageRepository $messageRepository,
        private readonly MessageThreadRepository $threadRepository,
    )
    {
    }

    #[Route('/inbox', name: 'inbox')]
    public function inbox(
        Request $request,
    ): Response {
        $user = $this->getUser();
        $tab  = MessageTab::Primary;
        $page = max(1, (int) $request->query->get('page', 1));

        // Handle tab switching
        $tabParam = $request->query->get('tab');
        if ($tabParam !== null) {
            $tab = MessageTab::from($tabParam);
        }

        $mailboxIds = $this->mailboxRepository->getIdsOfActiveInboxMailboxesForUser($user);
        $threads = $this->threadRepository->findForUnifiedInbox($user, $tab, $page);
        $total   = $this->threadRepository->countForUnifiedInbox($user, $tab);

        return $this->render('mail/inbox.html.twig', [
            'threads'  => $threads,
            'mailboxIds' => $mailboxIds,
            'tab'      => $tab,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/starred', name: 'starred')]
    public function starred():Response
    {
        return $this->render('mail/starred.html.twig', []);
    }

    #[Route('/sent', name: 'sent')]
    public function sent():Response
    {
        return $this->render('mail/sent.html.twig', []);
    }

    #[Route('/drafts', name: 'drafts')]
    public function drafts():Response
    {
        return $this->render('mail/drafts.html.twig', []);
    }

    #[Route('/trash', name: 'trash')]
    public function trash():Response
    {
        return $this->render('mail/trash.html.twig', []);
    }

    #[Route('/account/{account}/folders', name: 'account_folders')]
    public function accountFolders(Account $account): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        // Security check — account must belong to current user
        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $mailboxes = $this->mailboxRepository->findBy(
            ['account' => $account],
            ['name' => 'ASC'],
        );

        return $this->render('mail/_account_folders.html.twig', [
            'account'  => $account,
            'mailboxes' => $mailboxes,
        ]);
    }

    #[Route('/account/{account}/{mailbox}', name: 'account_mailbox')]
    public function mailbox(Account $account, string $mailbox): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $mailboxEntity = $this->mailboxRepository->findOneBy(['account' => $account, 'name' => $mailbox]);

        if (null === $mailboxEntity) {
            throw $this->createNotFoundException();
        }

        $messages = $this->messageRepository->findByMailboxOrderedByReceivedDate($mailboxEntity);

        return $this->render('mail/mailbox.html.twig', [
            'account' => $account,
            'mailbox' => $mailboxEntity,
            'messages' => $messages,
        ]);
    }

    #[Route('/message/{id}', name: 'message')]
    public function message(Message $message, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $mailbox = $message->getMailbox();
        $account = $mailbox->getAccount();

        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($request->headers->get('X-Requested-With') === 'fetch') {
            // JS fetch — return just the message content fragment
            return $this->render('mail/_message_content.html.twig', [
                'message' => $message,
            ]);
        }

        // Direct load / refresh / bookmark — render the full mailbox page
        // with the reading pane already open
        return $this->render('mail/mailbox.html.twig', [
            'mailbox' => $mailbox,
            'account' => $account,
            'selectedMessage' => $message,
        ]);
    }


    #[Route('/thread/{id}', name: 'thread')]
    public function thread(MessageThread $thread, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $account = $thread->getAccount();
        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Get the latest message in the thread to display in the reading pane
        $messages = $thread->getMessages();
        $latestMessage = null;
        if (!$messages->isEmpty()) {
            // Assuming messages are ordered by receivedAt;
            // if not, we'd typically sort them or pick the one with max date.
            // For this implementation, we'll grab the last one in the collection.
            $latestMessage = $messages->last();
        }

        // A thread can belong to multiple mailboxes; we'll pick the first one for the context.
        $mailbox = $thread->getMailboxes()->first();

        if ($request->headers->get('X-Requested-With') === 'fetch') {
            return $this->render('mail/_thread_content.html.twig', [
                'thread' => $thread,
            ]);
        }

        return $this->render('mail/thread.html.twig', [
            'thread'  => $thread,
            'account' => $account,
        ]);
    }
}
