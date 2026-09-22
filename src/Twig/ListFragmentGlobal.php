<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Whether this request wants the message list on its own, and how much of the
 * document around it the caller still needs.
 *
 * Two callers ask for the list alone, for two different reasons, and they are
 * answered differently because they read the answer differently.
 *
 * THE POLL asks with a header of our own. It fetches the current URL, lifts one
 * frame out of the response with a DOMParser and swaps the regions of it that
 * have gone stale — see mail_pane_controller#_refreshList. It reads nothing but
 * that frame, so it is sent nothing but that frame: no document, no head, not
 * even a title.
 *
 * A NAVIGATION asks with Turbo's own `Turbo-Frame`, which Turbo sets on every
 * frame navigation: the sidebar's folder links, the inbox's category tabs, the
 * pager, the sort menu and the search box all navigate this one frame. Turbo
 * takes the matching frame out of the response and discards the rest of the
 * BODY — but not the head, and that is where this class's previous conclusion
 * ("only the poll, which wants no title, gets the short answer") came from and
 * where it was wrong.
 *
 * ── What Turbo does with the head of a frame response ────────────────────────
 * A frame carrying `data-turbo-action` proposes a page visit for the response
 * it has just rendered — FrameController#proposeVisitIfNavigatedWithAction —
 * with `willRender: false`, so the body is not replaced. Everything BEFORE the
 * body is done anyway, and there are two gates in it, both read out of our own
 * vendored copy (assets/vendor/@hotwired/turbo, 8.0.23) and both confirmed in
 * Chromium against a running instance.
 *
 * FIRST GATE: `PageRenderer#shouldRender` is `isVisitable` and
 * `trackedElementsAreIdentical`, where the tracked elements are the ones
 * carrying `data-turbo-track="reload"`. No template here writes that attribute
 * and the head carries three of them regardless, because `importmap()` marks
 * everything it emits with it. A response whose head lacks them has a
 * different signature, so `shouldRender` is false — and with `willRender`
 * false as well, `View#render` does NOTHING AT ALL: no head merge, no title,
 * no reload, no error. MEASURED: serving the frame plus a <title> and clicking
 * from the inbox to Sent advances the URL and leaves the tab still reading
 * "(3) Inbox — Mail". Which is the very objection this class used to raise,
 * arriving by a route nobody had traced.
 *
 * SECOND GATE, for a head that gets past the first: `mergeProvisionalElements()`
 * removes from the LIVE head every meta, title and link the response does not
 * also carry, then appends the ones it does. So a fragment that carried the
 * importmap and little else would strip the page it was serving: the csrf token
 * (every fetch-based write then posts an empty one), the turbo-cache-control
 * that keeps Back from restoring a stale list (_layout/_mailbox.html.twig says
 * at length why that meta is there), the title-count-key the sidebar rewrites
 * the tab's "(n)" from, the viewport, the manifest and the icons.
 *
 * ONE RULE GETS PAST BOTH: send the head the page would have sent. The
 * navigation answer therefore renders app.html.twig's head exactly as a full
 * visit does — same template, same order — so the signature matches and the
 * merge is a no-op. MEASURED across that same click: title "(3) Inbox — Mail"
 * to "Sent — Mail", and csrf-token, turbo-cache-control, title-count-key, all
 * nine metas, all 117 head links and <html lang> identical before and after.
 *
 * What it drops is only what is both expensive and provably unread: the body
 * around the frame. That is the sidebar, the topbar, the calendar pane and the
 * reading pane — 21–24 queries on a fifty-row list down to 9–13, see
 * ThreadListQueryBudgetTest.
 *
 * ── Why the sidebar does not need re-rendering on a list navigation ──────────
 * It is not that nothing in it can change; it is that everything which can is
 * already pushed rather than pulled. A label created, renamed or deleted
 * re-renders both sidebars through LabelController::labelListsStream(). Reading
 * a conversation moves the counts, and ui--sidebar#refreshCounts patches the
 * badges from the sync event. Switching folder or searching changes neither the
 * counts nor the names. What is left is a label renamed in another tab or on
 * another device, which today's per-visit re-render happens to catch within one
 * navigation and now waits for the next sync — the price, named.
 *
 * ── No `Vary`, and it is not an oversight ───────────────────────────────────
 * One URL now has two representations, which is normally an invitation to
 * write `Vary: Turbo-Frame`. There is nothing here for such a header to
 * protect: a mail list is answered `Cache-Control: max-age=0, must-revalidate,
 * private` (measured off a live response), so no shared cache may store it at
 * all and the browser's own copy may not be used without asking again — and
 * the ask carries the header, so it gets the representation it asked for. The
 * poll's header has had the same property since it landed. If a list is ever
 * given a real max-age, this paragraph is the one to come back to.
 *
 * @see templates/_layout/_mailbox.html.twig  which of the two answers is built
 * @see assets/controllers/mail/mail_pane_controller.js  the poll
 */
final readonly class ListFragmentGlobal
{
    /** Sent by mail_pane_controller when it refreshes the list in place. */
    public const string HEADER = 'X-List-Fragment';

    /** Turbo's, on every frame navigation and on every prefetch of one. */
    public const string TURBO_HEADER = 'Turbo-Frame';

    /**
     * The frame the list lives in — see templates/_layout/_mailbox.html.twig.
     *
     * Compared exactly, so nothing else on the page is affected by any of this:
     * the compose dock, the modal, the calendar pane and the account folder
     * lists are frames too, and every one of them navigates on its own and
     * wants the document it asks for.
     */
    public const string LIST_FRAME = 'inbox-list-frame';

    public function __construct(private RequestStack $requests) {}

    /** The in-place refresh: the frame, and nothing else at all. */
    public function isPoll(): bool
    {
        return $this->requests->getCurrentRequest()?->headers->has(self::HEADER) ?? false;
    }

    /** A Turbo navigation OF the list frame: the document, with only the frame in its body. */
    public function isNavigation(): bool
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            return false;
        }

        // The poll wins if both are somehow present. It is the stricter answer
        // of the two, and the caller that sends our header parses the response
        // itself rather than letting Turbo near it.
        if (true === $request->headers->has(self::HEADER)) {
            return false;
        }

        return self::LIST_FRAME === $request->headers->get(self::TURBO_HEADER);
    }
}
