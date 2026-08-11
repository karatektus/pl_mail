<?php

namespace App\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Twig\SidebarCounts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly AccountRepository $accountRepository,
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
        $tabTotals  = $this->threadRepository->countByCategoryForUnifiedInbox($user);

        // A tab nobody's mail lands in is a door to an empty room — Gmail
        // itself has quietly retired Forums. Primary always shows, a category
        // shows while it holds anything at all, and the tab being LOOKED AT
        // stays even when its last thread just left, so the ground does not
        // vanish underfoot; it disappears on the next natural navigation.
        $tabs = array_values(array_filter(
            MessageCategory::cases(),
            static fn (MessageCategory $case): bool => MessageCategory::Primary === $case
                || $case === $tab
                || ($tabTotals[$case->value] ?? 0) > 0,
        ));

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/inbox.html.twig', [
            'threads'    => $threads,
            'tab'        => $tab,
            'tabs'       => $tabs,
            'tabCounts'  => $tabCounts,
            'page'       => $page,
            'total'      => $total,
            'per_page'   => 50,
        ]);
    }

    /**
     * Label view by path ("Work/Invoices"). One label now spans every account,
     * so the path resolves to a single Label and the thread list is naturally
     * cross-account — this used to fan out to one Label per account and union
     * the results.
     *
     * Declared before the id-based route so "/label/path/…" never collides
     * with "/label/{id}".
     */
    #[Route('/label/path/{path}', name: 'label_path', requirements: ['path' => '.+'])]
    public function labelPathView(string $path, Request $request): Response
    {
        $label = $this->labelRepository->findOneByPathForUser($this->getUser(), $path);

        if (null === $label) {
            throw $this->createNotFoundException();
        }

        return $this->renderLabel($label, $request);
    }

    #[Route('/label/{id}', name: 'label')]
    public function labelView(Label $label, Request $request): Response
    {
        if ($label->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->renderLabel($label, $request);
    }

    private function renderLabel(Label $label, Request $request): Response
    {
        $account = $this->requestedAccount($request);
        $page    = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForLabel($label, $account, $page);
        $total   = $this->threadRepository->countForLabel($label, $account);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/label.html.twig', [
            'label'    => $label,
            'account'  => $account,
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    /**
     * The `?account=` a label view may be narrowed by.
     *
     * A query parameter rather than a second route: it is a filter on the same
     * view, and the label id alone still has to resolve — the sidebar's own
     * label list means "across every account" and links without it.
     *
     * Ownership is checked here rather than trusted, because this comes
     * straight off the query string: without it, appending someone else's
     * account id would scope a label you own to threads you do not.
     */
    private function requestedAccount(Request $request): ?Account
    {
        $id = $request->query->get('account');

        if (null === $id || '' === $id) {
            return null;
        }

        $account = $this->accountRepository->find($id);

        if (null === $account || $account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $account;
    }

    /**
     * Everything in one account — what clicking the account itself in the
     * sidebar now does. Its labels sit underneath for narrowing further.
     */
    #[Route('/account/{account}', name: 'account')]
    public function accountView(Account $account, Request $request): Response
    {
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForAccount($account, $page);
        $total   = $this->threadRepository->countForAccount($account);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/account.html.twig', [
            'account'  => $account,
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
     * Spam role view.
     *
     * Its sidebar entry is behind the label-settings eye toggle, like Archive's
     * — but the route is unconditional, exactly as Archive's is. Gating the
     * ROUTE on a display preference would make a bookmark stop working because
     * someone tidied their sidebar, and the folder rows under an expanded
     * account already link to this mail by label id whether the toggle is on
     * or not.
     */
    #[Route('/spam', name: 'spam')]
    public function spam(Request $request): Response
    {
        $user    = $this->getUser();
        $page    = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForRole($user, LabelRole::Spam, $page);
        $total   = $this->threadRepository->countForRole($user, LabelRole::Spam);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/spam.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => 50,
        ]);
    }

    /** Archive role view. */
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

    /**
     * Snoozed role view — where conversations wait for their wake time.
     *
     * Ordered by lastMessageAt like every other list rather than by wake time,
     * which is the less obvious choice: the row shows when it comes back, so
     * sorting by it would be legible. But this list is reached to *find* a
     * conversation, and "the one from Tuesday" is how people look, not "the
     * one due third".
     */
    #[Route('/snoozed', name: 'snoozed')]
    public function snoozed(Request $request): Response
    {
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $threads = $this->threadRepository->findForRole($user, LabelRole::Snoozed, $page);
        $total   = $this->threadRepository->countForRole($user, LabelRole::Snoozed);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/snoozed.html.twig', [
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

        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Labels materialized on this account — the per-account folder frame
        // is the one place bindings surface, since it answers "what does this
        // account actually have".
        $labels = array_values(array_filter(
            $this->labelRepository->findBoundToAccount($account),
            static fn(Label $label): bool => true === $label->isVisible,
        ));

        return $this->render('mail/_account_labels.html.twig', [
            'account' => $account,
            'labels'  => $labels,
        ]);
    }

    /**
     * Remembers which account's folder list is open, so the sidebar can render
     * it already expanded rather than expanding it afterwards.
     *
     * Server-side rather than in localStorage, for the reason the calendar
     * pane's width is: the sidebar re-renders on every visit, and a list
     * restored by JavaScript after that render is a list the user watches
     * blink on every click.
     */
    #[Route('/sidebar/account-expanded', name: 'sidebar_account_expanded', methods: ['POST'])]
    public function sidebarAccountExpanded(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (false === $this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user      = $this->getUser();
        $accountId = $request->toArray()['account'] ?? null;

        // Ownership is checked even though this is only a preference: the id
        // comes back in the next render, where it decides whose folder list
        // gets read.
        $account = is_int($accountId) ? $this->accountRepository->find($accountId) : null;

        $user->expandedAccountId = (null !== $account && $account->usr === $user)
            ? (int) $account->id
            : null;

        $em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Unread badge counts for the sidebar, keyed the same way the badges are
     * (see the unread_badge macro in _partials/_sidebar.html.twig).
     *
     * The sidebar controller polls this after a Mercure sync and patches the
     * badges in place rather than re-rendering the nav, which would drop the
     * scroll position and any collapsed tree mid-sync.
     */
    #[Route('/sidebar/counts', name: 'sidebar_counts', methods: ['GET'])]
    public function sidebarCounts(SidebarCounts $counts): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = ['starred' => $counts->forStarred()];

        foreach (LabelRole::cases() as $role) {
            $payload['role:' . $role->value] = $counts->forRole($role);
        }

        // One key per label now that the sidebar renders labels directly —
        // this used to also emit "node:<path>" keys for the merged tree.
        $labels = $this->labelRepository->findVisibleForUser($this->getUser());

        foreach ($labels as $label) {
            $payload['label:' . $label->id] = $counts->forLabel($label);
        }

        // The expanded account's folder rows count within that account and are
        // keyed accordingly, so they need their own entries — patched with the
        // user-wide number they would claim mail the list beside them does not
        // show. Only the expanded account: it is the only one on screen.
        /** @var User $user */
        $user    = $this->getUser();
        $account = null === $user->expandedAccountId
            ? null
            : $this->accountRepository->find($user->expandedAccountId);

        if (null !== $account && $account->usr === $user) {
            foreach ($labels as $label) {
                $payload['label:' . $label->id . ':account:' . $account->id]
                    = $counts->forLabelInAccount($label, $account);
            }
        }

        return $this->json($payload);
    }

    #[Route('/message/{id}', name: 'message')]
    public function message(Message $message, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $thread = $message->thread;
        $account = $thread->account;

        if ($account->usr !== $this->getUser()) {
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

        $account = $thread->account;
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // The association is ordered by receivedAt ASC, so the last entry is
        // the newest message.
        $messages      = $thread->messages;
        $latestMessage = false === $messages->isEmpty() ? $messages->last() : null;

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
