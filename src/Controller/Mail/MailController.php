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
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    /** Rows per page, for every list view and the pagination that clamps them. */
    private const int PER_PAGE = 50;

    /**
     * The page to render — or a redirect to the last page that exists.
     *
     * `?page=999` on a 193-row folder used to render the header "201–193 of
     * 193" above nothing at all: the lower bound was clamped and the upper one
     * was not, so the arithmetic ran off the end of the list and said so. The
     * page number is user input via the URL, and the honest answer to a page
     * that does not exist is the last one that does.
     *
     * A redirect rather than a silent clamp, so the address bar stops claiming
     * a page the user is not on — otherwise reloading, bookmarking or sharing
     * the URL all carry the phantom page number along.
     *
     * Built from the current path and query rather than from a route name: this
     * serves ten list actions whose route parameters differ, and the one thing
     * they share is that only `page` needs changing.
     */
    private function pageOrRedirect(Request $request, int $total): int|RedirectResponse
    {
        $page     = max(1, (int) $request->query->get('page', 1));
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        if ($page <= $lastPage) {
            return $page;
        }

        $query = $request->query->all();

        // Page 1 is the bare URL. Carrying `?page=1` would make the canonical
        // first page of every list a different URL depending on how it was
        // reached.
        if (1 === $lastPage) {
            unset($query['page']);
        } else {
            $query['page'] = $lastPage;
        }

        return $this->redirect(
            $request->getPathInfo() . ([] === $query ? '' : '?' . http_build_query($query)),
        );
    }

    #[Route('/inbox', name: 'inbox')]
    public function inbox(
        Request $request,
    ): Response {
        $user = $this->getUser();

        // tryFrom, not from: `?tab=` is a URL anyone can edit, and a category
        // that does not exist is a mistyped or stale link, not a server fault.
        // ValueError out of an enum here surfaced as a 500 on ?tab=quatsch.
        // Falling back to Primary shows the inbox, which is what the person
        // following that link was after.
        $tab = MessageCategory::tryFrom((string) $request->query->get('tab', ''))
            ?? MessageCategory::Primary;

        $total = $this->threadRepository->countForUnifiedInbox($user, $tab);
        $page  = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $threads    = $this->threadRepository->findForUnifiedInbox($user, $tab, $page);
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
            'per_page'   => self::PER_PAGE,
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

    #[Route('/label/{id}', name: 'label', requirements: ['id' => '\d+'])]
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
        $total   = $this->threadRepository->countForLabel($label, $account);
        $page    = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $threads = $this->threadRepository->findForLabel($label, $account, $page);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/label.html.twig', [
            'label'    => $label,
            'account'  => $account,
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => self::PER_PAGE,
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
    #[Route('/account/{account}', name: 'account', requirements: ['account' => '\d+'])]
    public function accountView(Account $account, Request $request): Response
    {
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $total   = $this->threadRepository->countForAccount($account);
        $page    = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $threads = $this->threadRepository->findForAccount($account, $page);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/account.html.twig', [
            'account'  => $account,
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => self::PER_PAGE,
        ]);
    }

    #[Route('/starred', name: 'starred')]
    public function starred(Request $request): Response
    {
        $user  = $this->getUser();
        $total   = $this->threadRepository->countForStarred($user);
        $page    = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $threads = $this->threadRepository->findForStarred($user, $page);

        $this->threadRepository->preloadLabels($threads);

        return $this->render('mail/starred.html.twig', [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => self::PER_PAGE,
        ]);
    }

    #[Route('/sent', name: 'sent')]
    public function sent(Request $request): Response
    {
        return $this->renderRole($request, LabelRole::Sent, 'mail/sent.html.twig');
    }

    #[Route('/drafts', name: 'drafts')]
    public function drafts(Request $request): Response
    {
        return $this->renderRole($request, LabelRole::Drafts, 'mail/drafts.html.twig');
    }

    #[Route('/trash', name: 'trash')]
    public function trash(Request $request): Response
    {
        return $this->renderRole($request, LabelRole::Trash, 'mail/trash.html.twig');
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
        return $this->renderRole($request, LabelRole::Spam, 'mail/spam.html.twig');
    }

    /** Archive role view. */
    #[Route('/archive', name: 'archive')]
    public function archive(Request $request): Response
    {
        return $this->renderRole($request, LabelRole::Archive, 'mail/archive.html.twig');
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
        return $this->renderRole($request, LabelRole::Snoozed, 'mail/snoozed.html.twig');
    }

    /**
     * The six role views — Sent, Drafts, Trash, Spam, Archive, Snoozed — differ
     * only in which role they count and which template they hand it to.
     *
     * They were six copies of the same eight lines until the page clamp had to
     * be added to all of them, which is the usual way a fix reaches five of six
     * places. One body means the next such change lands everywhere or nowhere.
     */
    private function renderRole(Request $request, LabelRole $role, string $template): Response
    {
        $user  = $this->getUser();
        $total = $this->threadRepository->countForRole($user, $role);
        $page  = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $threads = $this->threadRepository->findForRole($user, $role, $page);

        $this->threadRepository->preloadLabels($threads);

        return $this->render($template, [
            'threads'  => $threads,
            'page'     => $page,
            'total'    => $total,
            'per_page' => self::PER_PAGE,
        ]);
    }

    #[Route('/account/{account}/folders', name: 'account_folders', requirements: ['account' => '\d+'])]
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

    #[Route('/message/{id}', name: 'message', requirements: ['id' => '\d+'])]
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


    /**
     * `{id}` is constrained to digits so a non-numeric id fails to MATCH the
     * route and becomes a 404. Without it the id reaches the entity resolver,
     * which asks Postgres for a MessageThread whose bigint id is 'abc' and gets
     * back a driver-level type error — a 500 for what is only a bad link.
     */
    #[Route('/thread/{id}', name: 'thread', requirements: ['id' => '\d+'])]
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
