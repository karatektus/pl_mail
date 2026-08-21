<?php

namespace App\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Insight\MailInsightRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\NewMailMarkers;
use App\Service\Mail\SidebarCounts;
use App\Service\Mail\ThreadListRenderer;
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
        private readonly ThreadListRenderer $listRenderer,
        private readonly MailInsightRepository $insightRepository,
    )
    {
    }

    /** Rows per page, for every list view and the pagination that clamps them. */
    private const int PER_PAGE = 50;

    /** Where the chosen list order is remembered between visits. */
    private const string SORT_SESSION_KEY = 'mail.list_sort';

    /**
     * Which order to list in: what the URL asks for, else what was asked for
     * last time, else newest-first.
     *
     * Per SESSION and across all list views, rather than per view or per user.
     * Per view would mean Inbox and Trash disagreeing about a preference the
     * user expressed once and thinks of as "how I read my mail"; storing it on
     * the user would make a choice made to scan one backlog permanent, and
     * survive into the next month on another device. A session is the span over
     * which "I am reading oldest-first right now" is actually true.
     *
     * Writing on read is deliberate: the menu is a set of plain links (see
     * _sort_menu.html.twig), so following one IS the request that records the
     * choice. That keeps the whole feature free of JavaScript, at the cost of a
     * GET with a side effect — acceptable because the effect is a display
     * preference and the same GET is idempotent in every other respect.
     */
    private function listSort(Request $request): ListSortOrder
    {
        $session = $request->getSession();
        $asked   = $request->query->get('sort');

        if (null !== $asked && '' !== $asked) {
            $sort = ListSortOrder::fromSetting($asked);
            $session->set(self::SORT_SESSION_KEY, $sort->value);

            return $sort;
        }

        return ListSortOrder::fromSetting($session->get(self::SORT_SESSION_KEY));
    }

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

    /**
     * Render a thread list through the shared collect-render-then-mark path.
     *
     * A thin forward to ThreadListRenderer, which SearchController uses too —
     * search results are rows the user has been shown like any others.
     *
     * @param MessageThread[]      $threads
     * @param array<string, mixed> $parameters
     */
    private function renderList(Request $request, string $template, array $threads, array $parameters): Response
    {
        return $this->listRenderer->render($request, $template, $threads, $parameters);
    }

    /**
     * "These rows are on screen" — the client half of the marker.
     *
     * This exists because the server cannot tell a render from a display, and
     * one particular gap between the two made the badge permanent. Turbo 8
     * prefetches links on hover and then reuses that response for the click, so
     * every visit to a list reached from the sidebar or the category tabs
     * arrived carrying `X-Sec-Purpose: prefetch`. renderList() correctly
     * declined to mark a speculative fetch; nothing ever marked the visit,
     * because there was no second request to mark it on. The badge survived
     * navigation, reload and everything else, forever.
     *
     * So the DOM reports in. Rows that actually entered the document say so,
     * which is the statement the feature has always claimed to make, and the
     * one no response header can make on their behalf.
     *
     * Idempotent by construction: markListedForUser() only writes where
     * listedAt is still null, so the ordinary non-prefetched case — already
     * marked server-side during the render — costs one UPDATE matching no rows.
     */
    #[Route('/threads/listed', name: 'threads_listed', methods: ['POST'])]
    public function markListed(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $body = json_decode((string) $request->getContent(), true);
        $ids  = \is_array($body) && \is_array($body['ids'] ?? null) ? $body['ids'] : [];

        // Ints only, and capped. The cap is not a security boundary — ownership
        // is — but a page renders PER_PAGE rows, so a body an order of
        // magnitude past that is not a list anybody looked at.
        $ids = \array_slice(
            \array_values(\array_filter(\array_map(
                static fn (mixed $id): int => (int) $id,
                $ids,
            ), static fn (int $id): bool => $id > 0)),
            0,
            self::PER_PAGE * 2,
        );

        $marked = $this->threadRepository->markListedForUser(
            $ids,
            $this->getUser(),
            new \DateTimeImmutable(),
        );

        return new JsonResponse(['marked' => $marked]);
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

        $unreadOnly = $this->unreadOnly($request);
        $total      = $this->threadRepository->countForUnifiedInbox($user, $tab, $unreadOnly);
        $page       = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $sort       = $this->listSort($request);
        $threads    = $this->threadRepository->findForUnifiedInbox($user, $tab, $page, self::PER_PAGE, $sort, $unreadOnly);
        // The unread numbers on the tabs come from the sidebarCounts global in
        // the template — the same memoised read the counts endpoint answers
        // with, so the two cannot disagree. Only which tabs EXIST is decided
        // here.
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

        $this->threadRepository->preloadForRows($threads);

        return $this->renderList($request, 'mail/inbox.html.twig', $threads, [
            'tab'         => $tab,
            'tabs'        => $tabs,
            'page'        => $page,
            'total'       => $total,
            'per_page'    => self::PER_PAGE,
            'list_sort'   => $sort,
            'unread_only' => $unreadOnly,
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
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);

        return $this->renderLabel($label, $request);
    }

    private function renderLabel(Label $label, Request $request): Response
    {
        $account    = $this->requestedAccount($request);
        $unreadOnly = $this->unreadOnly($request);
        $total      = $this->threadRepository->countForLabel($label, $account, $unreadOnly);
        $page       = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $sort    = $this->listSort($request);
        $threads = $this->threadRepository->findForLabel($label, $account, $page, self::PER_PAGE, $sort, $unreadOnly);

        $this->threadRepository->preloadForRows($threads);

        return $this->renderList($request, 'mail/label.html.twig', $threads, [
            'label'       => $label,
            'account'     => $account,
            'page'        => $page,
            'total'       => $total,
            'per_page'    => self::PER_PAGE,
            'list_sort'   => $sort,
            'unread_only' => $unreadOnly,
        ]);
    }

    /**
     * Whether this view was opened from an unread badge.
     *
     * `?unread=1`, a query parameter on the same view rather than a route of
     * its own, for the reason `?account=` is one: it is a filter, the view is
     * otherwise identical, and every link that already exists — a sort, a page,
     * the account narrowing — has to keep working underneath it.
     *
     * Read as a boolean rather than compared to "1", so `?unread`, `?unread=1`
     * and `?unread=true` all mean the same thing; anything falsy, including the
     * parameter being absent, is the ordinary unfiltered view. A URL is
     * something people edit and hand to each other, and the failure mode of
     * being strict here is a link that silently shows everything while claiming
     * to show unread.
     */
    private function unreadOnly(Request $request): bool
    {
        return $request->query->getBoolean('unread');
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

        if (null === $account) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        return $account;
    }

    /**
     * One account's INBOX — what clicking the account itself in the sidebar
     * does. Its labels sit underneath for going anywhere else in it.
     *
     * It used to be everything in the account, whatever it was labelled, and
     * that is the bug this fixes. Clicking your own address opened a list with
     * your sent mail interleaved through the mail you had received, drafts and
     * spam among it — an archive, when what a person means by clicking a
     * mailbox is "show me what came in". The sidebar's top-level Inbox is that
     * question across every account; this is the same question about one.
     *
     * Nothing is hidden by it: Sent, Drafts, Spam and the bin are all folder
     * rows under the account, one click further, which is where mail that is
     * not incoming has always been reachable.
     */
    #[Route('/account/{account}', name: 'account', requirements: ['account' => '\d+'])]
    public function accountView(Account $account, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $total   = $this->threadRepository->countForAccountInbox($account);
        $page    = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $sort    = $this->listSort($request);
        $threads = $this->threadRepository->findForAccountInbox($account, $page, self::PER_PAGE, $sort);

        $this->threadRepository->preloadForRows($threads);

        return $this->renderList($request, 'mail/account.html.twig', $threads, [
            'account'   => $account,
            'page'      => $page,
            'total'     => $total,
            'per_page'  => self::PER_PAGE,
            'list_sort' => $sort,
        ]);
    }

    #[Route('/starred', name: 'starred')]
    public function starred(Request $request): Response
    {
        $user       = $this->getUser();
        $unreadOnly = $this->unreadOnly($request);
        $total      = $this->threadRepository->countForStarred($user, $unreadOnly);
        $page       = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $sort    = $this->listSort($request);
        $threads = $this->threadRepository->findForStarred($user, $page, self::PER_PAGE, $sort, $unreadOnly);

        $this->threadRepository->preloadForRows($threads);

        return $this->renderList($request, 'mail/starred.html.twig', $threads, [
            'page'        => $page,
            'total'       => $total,
            'per_page'    => self::PER_PAGE,
            'list_sort'   => $sort,
            'unread_only' => $unreadOnly,
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
        $user       = $this->getUser();
        $unreadOnly = $this->unreadOnly($request);
        $total      = $this->threadRepository->countForRole($user, $role, $unreadOnly);
        $page       = $this->pageOrRedirect($request, $total);

        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $sort    = $this->listSort($request);
        $threads = $this->threadRepository->findForRole($user, $role, $page, self::PER_PAGE, $sort, $unreadOnly);

        $this->threadRepository->preloadForRows($threads);

        return $this->renderList($request, $template, $threads, [
            'page'        => $page,
            'total'       => $total,
            'per_page'    => self::PER_PAGE,
            'list_sort'   => $sort,
            'unread_only' => $unreadOnly,
        ]);
    }

    #[Route('/account/{account}/folders', name: 'account_folders', requirements: ['account' => '\d+'])]
    public function accountFolders(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

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
            'frameId' => $this->folderFrameId($request, $account),
        ]);
    }

    /**
     * The id the answering frame must wear.
     *
     * The desktop rail and the mobile drawer both render the sidebar into the
     * same document, so each account's folder frame needs an id of its own —
     * and Turbo matches a frame response by the id of the frame that asked for
     * it, so the id has to come back on the reply too.
     *
     * Taken from the request rather than derived, because only the caller knows
     * which of the two it is; validated hard, because it lands in an id
     * attribute. Anything that is not this account's own frame falls back to
     * the bare name, which is the desktop one.
     */
    private function folderFrameId(Request $request, Account $account): string
    {
        $default   = 'account-folders-' . $account->id;
        $requested = (string) $request->query->get('frame', '');

        return in_array($requested, [$default, $default . '-drawer'], true)
            ? $requested
            : $default;
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
     * Remembers which sidebar sections and label trees are collapsed.
     *
     * The same shape as sidebarAccountExpanded above, and per user rather than
     * per browser for the same two reasons: the state is rendered into the
     * first paint, so nothing collapses after it is already on screen, and a
     * preference about your own nav should follow you to your other devices.
     *
     * The key is not validated against anything, and deliberately: label trees
     * are keyed by full name, so validating would mean a lookup per toggle to
     * learn nothing — an unknown key collapses nothing, because the only thing
     * that ever reads this bag is a template asking "is MY key in here?". It is
     * bounded instead, so the bag cannot be used as free storage.
     */
    #[Route('/sidebar/section-collapsed', name: 'sidebar_section_collapsed', methods: ['POST'])]
    public function sidebarSectionCollapsed(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (false === $this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();

        $payload   = $request->toArray();
        $key       = $payload['key'] ?? null;
        $collapsed = $payload['collapsed'] ?? null;

        if (false === is_string($key) || '' === $key || 256 < strlen($key) || false === is_bool($collapsed)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        // Collapsing is always allowed; it is only the growth of the list that
        // needs a ceiling, and a user with more than this many collapsed things
        // has a nav problem this preference will not fix.
        if (true === $collapsed && 500 <= count($user->collapsedSidebarSections)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $user->setSidebarSectionCollapsed($key, $collapsed);

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
    public function sidebarCounts(SidebarCounts $counts, NewMailMarkers $newMail): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = [
            'starred'                     => $counts->forStarred(),
            NewMailMarkers::STARRED_KEY   => $newMail->forStarred(),
            // The collapsed LABELS heading's roll-up. Emitted whether or not
            // the section is currently collapsed, because the badge is
            // rendered either way — hidden by the disclosure rather than
            // omitted, which is the same rule every other badge here follows
            // and the reason a collapsed section's numbers do not go stale.
            'labels:unread'               => $counts->unreadInLabels(),
        ];

        // The new-mail marks ride on this same payload rather than on an
        // endpoint of their own. They are patched by the same controller on the
        // same sync event, and two requests answering one question would let
        // the badge and the mark beside it disagree for a moment about the same
        // mail. The keys are namespaced "new:" so nothing here can collide with
        // an unread key — the mark means something different and must never be
        // fed to a badge that would print it as a number.
        //
        // A category tab makes two statements, so two families go out per
        // category: its new-arrivals count ("new:category:") and the senders
        // of those arrivals ("senders:category:" — the one whose values are
        // strings, which is exactly why it has a namespace of its own). No
        // unread count: the tabs deliberately do not show one — new is the
        // only number a tab can say that nothing else already says.
        foreach (MessageCategory::cases() as $category) {
            $payload[NewMailMarkers::categoryKey($category)]        = $newMail->forCategory($category);
            $payload[NewMailMarkers::categorySendersKey($category)] = implode(', ', $newMail->sendersForCategory($category));
        }

        // forRoleBadge(), not forRole(): Trash and Drafts show a total rather
        // than an unread count, and this endpoint has to say the same thing the
        // server-rendered badge did or the first sync would silently change the
        // number under the user. See SidebarCounts::TOTAL_ROLES.
        foreach (LabelRole::cases() as $role) {
            $payload['role:' . $role->value]      = $counts->forRoleBadge($role);
            $payload[NewMailMarkers::roleKey($role)] = $newMail->forRole($role);
        }

        // One key per label now that the sidebar renders labels directly —
        // this used to also emit "node:<path>" keys for the merged tree.
        $labels = $this->labelRepository->findVisibleForUser($this->getUser());

        foreach ($labels as $label) {
            $payload['label:' . $label->id]            = $counts->forLabel($label);
            $payload[NewMailMarkers::labelKey($label)] = $newMail->forLabel($label);
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

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        // The conversation's insight cards, drawn above the body — one indexed
        // read by thread, empty for almost every thread and cheap when it is.
        $insights = $this->insightRepository->forThread($thread);

        if ($request->headers->get('X-Requested-With') === 'fetch') {
            // JS fetch — return just the message content fragment
            return $this->render('mail/_message_content.html.twig', [
                'message'  => $message,
                'insights' => $insights,
            ]);
        }

        // Direct load / refresh / bookmark — render the full mailbox page
        // with the reading pane already open
        return $this->render('mail/mailbox.html.twig', [
            'thread' => $thread,
            'account' => $account,
            'selectedMessage' => $message,
            'insights' => $insights,
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
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        // In conversation order — received by arrival, sent by send time — not
        // the association's receivedAt-only order, which drops this account's
        // own replies to the bottom of the thread (they have no receivedAt).
        // The last entry is then genuinely the newest message, which is what
        // the reply zone quotes and what opens expanded.
        $messages = $this->messageRepository->forThreadInConversationOrder($thread);
        $latest   = [] === $messages ? null : $messages[array_key_last($messages)];

        // Same read as message() above, for the same card in the same place.
        $insights = $this->insightRepository->forThread($thread);

        if ($request->headers->get('X-Requested-With') === 'fetch') {
            return $this->render('mail/_thread_content.html.twig', [
                'thread'   => $thread,
                'messages' => $messages,
                'latest'   => $latest,
                'insights' => $insights,
            ]);
        }

        return $this->render('mail/thread.html.twig', [
            'thread'   => $thread,
            'account'  => $account,
            'messages' => $messages,
            'latest'   => $latest,
            'insights' => $insights,
        ]);
    }
}
