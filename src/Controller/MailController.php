<?php

namespace App\Controller;

use App\Domain\Enum\LabelRole;
use App\Domain\Enum\MessageCategory;
use App\Entity\Account;
use App\Entity\Label;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Repository\LabelRepository;
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
        private readonly LabelRepository $labelRepository,
    )
    {
    }

    #[Route('/inbox', name: 'inbox')]
    public function inbox(
        Request $request,
    ): Response {
        $user = $this->getUser();
        $tab  = MessageCategory::Primary;
        $page = max(1, (int) $request->query->get('page', 1));

        $tabParam = $request->query->get('tab');
        if ($tabParam !== null) {
            $tab = MessageCategory::from($tabParam);
        }

        $threads    = $this->threadRepository->findForUnifiedInbox($user, $tab, $page);
        $total      = $this->threadRepository->countForUnifiedInbox($user, $tab);
        $tabCounts  = $this->threadRepository->countUnreadByCategoryForUnifiedInbox($user);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/inbox.html.twig', [
            'threads'    => $threads,
            'tab'        => $tab,
            'tabs'       => MessageCategory::cases(),
            'tabCounts'  => $tabCounts,
            'page'       => $page,
            'total'      => $total,
            'per_page'   => 50,
        ]);
    }

    /**
     * Merged label view: one sidebar entry may aggregate same-named labels
     * from several accounts; this resolves the path back to every matching
     * Label and lists threads across all of them.
     *
     * Declared before the id-based route so "/label/path/…" never collides
     * with "/label/{id}".
     */
    #[Route('/label/path/{path}', name: 'label_path', requirements: ['path' => '.+'])]
    public function labelPathView(string $path, Request $request): Response
    {
        $labels = $this->labelRepository->findByPathForUser($this->getUser(), $path);

        if (count($labels) === 0) {
            throw $this->createNotFoundException();
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForLabels($labels, $page);
        $total   = $this->threadRepository->countForLabels($labels);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/label.html.twig', [
            'label'    => $labels[0],
            'labels'   => $labels,
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/label/{id}', name: 'label')]
    public function labelView(Label $label, Request $request): Response
    {
        if ($label->account?->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForLabel($label, $page);
        $total   = $this->threadRepository->countForLabel($label);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/label.html.twig', [
            'label'    => $label,
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/starred', name: 'starred')]
    public function starred(Request $request): Response
    {
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForStarred($user, $page);
        $total   = $this->threadRepository->countForStarred($user);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/starred.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/sent', name: 'sent')]
    public function sent(Request $request): Response
    {
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForRole($user, LabelRole::Sent, $page);
        $total   = $this->threadRepository->countForRole($user, LabelRole::Sent);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/sent.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/drafts', name: 'drafts')]
    public function drafts(Request $request): Response
    {
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForRole($user, LabelRole::Drafts, $page);
        $total   = $this->threadRepository->countForRole($user, LabelRole::Drafts);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/drafts.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/trash', name: 'trash')]
    public function trash(Request $request): Response
    {
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForRole($user, LabelRole::Trash, $page);
        $total   = $this->threadRepository->countForRole($user, LabelRole::Trash);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/trash.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    /**
     * Archive role view — only reachable when the user has switched an
     * Archive label visible in the label settings.
     */
    #[Route('/archive', name: 'archive')]
    public function archive(Request $request): Response
    {
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForRole($user, LabelRole::Archive, $page);
        $total   = $this->threadRepository->countForRole($user, LabelRole::Archive);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/archive.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    #[Route('/account/{account}/folders', name: 'account_folders')]
    public function accountFolders(Account $account): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $labels = array_values(array_filter(
            $this->labelRepository->findForAccount($account),
            static fn(Label $label): bool => true === $label->isVisible,
        ));

        return $this->render('mail/_account_labels.html.twig', [
            'account' => $account,
            'labels'  => $labels,
        ]);
    }

    #[Route('/message/{id}', name: 'message')]
    public function message(Message $message, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $thread = $message->getThread();
        $account = $thread->getAccount();

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
            'thread' => $thread,
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
