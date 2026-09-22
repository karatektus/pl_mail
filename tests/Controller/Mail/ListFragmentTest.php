<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A list refresh gets the list, a list navigation gets the list and a head,
 * and only an ordinary visit gets the whole page.
 *
 * The poll used to ask for the current URL and get the whole document back —
 * topbar, sidebar, calendar pane, reading pane — then parse it client-side, lift
 * one frame out and discard the rest. Measured at 80 KB a time, eight times in
 * ten seconds of an idle inbox. Navigations did the same, and had the same
 * reason not to: Turbo takes the frame out of the response and throws the rest
 * of the body away.
 *
 * THE DIFFERENCE BETWEEN THE TWO ANSWERS IS THE HEAD, and that is what most of
 * this file pins. Turbo reads a frame response's head twice over: once to
 * decide whether to do anything with the response at all (the
 * `data-turbo-track="reload"` signature, which `importmap()` puts in our head
 * three times), and once to merge it into the live document — a merge that
 * REMOVES every meta, title and link the response leaves out. A head that is
 * short of the first makes the tab title silently stop following the folder; a
 * head that passes the first and is short of the second strips the csrf token
 * and the cache-control meta out of the page being served. Both were measured;
 * App\Twig\ListFragmentGlobal has the numbers.
 *
 * Which is why the assertion that matters here is not "it has a title" but
 * "its head is the page's head, element for element".
 */
final class ListFragmentTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string FRAGMENT_HEADER = 'HTTP_X_LIST_FRAGMENT';
    private const string TURBO_FRAME_HEADER = 'HTTP_TURBO_FRAME';
    private const string LIST_FRAME = 'inbox-list-frame';

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }

    // ── the poll: the frame, and nothing else at all ──────────────────────

    public function testAFragmentRequestAnswersWithTheListFrameAlone(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: [self::FRAGMENT_HEADER => self::LIST_FRAME]);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'id="inbox-list-frame"',
            $body,
            'the fragment is the frame, so the frame has to be in it',
        );

        // The point of the exercise: none of the document around it. The poll
        // reads this with a DOMParser and takes one element out of it, so a
        // head would be bytes nobody looks at — which is exactly what makes it
        // a different answer from the navigation below.
        self::assertStringNotContainsString('<html', $body);
        self::assertStringNotContainsString('<body', $body);
        self::assertStringNotContainsString('id="sidebar"', $body);
        self::assertStringNotContainsString('<title', $body);
    }

    /**
     * The saving, asserted as a fact rather than assumed from the absence of
     * tags above.
     */
    public function testTheFragmentIsAFractionOfThePage(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');
        $page = strlen((string) $client->getResponse()->getContent());

        $client->request('GET', '/mail/inbox', server: [self::FRAGMENT_HEADER => self::LIST_FRAME]);
        $fragment = strlen((string) $client->getResponse()->getContent());

        self::assertLessThan(
            $page / 2,
            $fragment,
            sprintf('the fragment (%d bytes) should be far smaller than the page (%d bytes)', $fragment, $page),
        );
    }

    /**
     * An ordinary visit is untouched — both fragment paths are opt-in, and
     * anything that is neither the poll nor a list navigation gets a document.
     */
    public function testAnOrdinaryVisitStillGetsTheWholePage(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<html', $body);
        self::assertStringContainsString('id="inbox-list-frame"', $body);
        self::assertStringContainsString('id="sidebar"', $body);
    }

    // ── a navigation: the frame, and the head the browser is holding ──────

    /**
     * The navigation answer drops the chrome and keeps the head.
     */
    public function testATurboFrameNavigationGetsTheFrameWithoutTheChrome(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: [self::TURBO_FRAME_HEADER => self::LIST_FRAME]);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="inbox-list-frame"', $body);
        self::assertStringContainsString('data-list-rendered="1"', $body);

        // The whole saving, in three assertions: the sidebar the click started
        // in, the calendar pane beside the list, and the reading pane behind
        // it. None of them can be changed by choosing a folder, and none of
        // them survive Turbo's frame extraction even when they are sent.
        self::assertStringNotContainsString('id="sidebar"', $body);
        self::assertStringNotContainsString('data-surface="sidebar"', $body);
        self::assertStringNotContainsString('data-mail--mail-pane-target="reading"', $body);
    }

    /**
     * The head survives a navigation intact — the assertion this whole change
     * rests on.
     *
     * Turbo answers a frame navigation by proposing a page visit for the same
     * response with `willRender: false`. The body is not replaced, but the head
     * is still read: first for the `data-turbo-track="reload"` signature, which
     * decides whether the visit renders anything at all, and then by a merge
     * that removes from the LIVE document every provisional head element (meta,
     * title, link) the response does not also carry. So the fragment's head is
     * not decoration: too little of it and the tab stops following the folder,
     * and the wrong part of it is deleted out of the page the user is sitting in.
     *
     * Compared element for element rather than spot-checking the few that would
     * hurt most, because the failure mode is silent and unbounded — a meta
     * added to app.html.twig next year is a meta this response has to carry, or
     * clicking a folder takes it away. If this fails, the two heads have drifted
     * apart, and the question is not which assertion to relax but why the
     * fragment is no longer rendering app.html.twig's head.
     */
    public function testTheNavigationFragmentCarriesTheWholeHeadOfThePage(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');
        $page = $this->headElements((string) $client->getResponse()->getContent());

        $client->request('GET', '/mail/inbox', server: [self::TURBO_FRAME_HEADER => self::LIST_FRAME]);
        $fragment = $this->headElements((string) $client->getResponse()->getContent());

        self::assertNotEmpty($page, 'no head found in the page — the extraction below is measuring nothing');
        self::assertSame(
            $page,
            $fragment,
            'Turbo deletes from the live document every head element the frame response omits',
        );
    }

    /**
     * Named on their own as well as compared as a set, because these three are
     * the ones whose loss would be a bug nobody would connect to a folder
     * click: writes refused for an empty token, the back button showing a stale
     * list again, and the tab's "(n)" frozen at whatever it said on load.
     */
    public function testTheNavigationFragmentKeepsTheMetasTheAppReadsAtRuntime(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: [self::TURBO_FRAME_HEADER => self::LIST_FRAME]);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('name="csrf-token"', $body);
        self::assertStringContainsString('name="turbo-cache-control" content="no-cache"', $body);
        self::assertStringContainsString('name="title-count-key" content="role:inbox"', $body);
    }

    /**
     * Turbo advances history from the response's title, so a navigation that
     * carried none would leave the tab showing the previous folder's name — or,
     * with no head at all, the bare URL.
     */
    public function testTheNavigationFragmentCarriesTheTitleOfThePageItIsFrom(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: [self::TURBO_FRAME_HEADER => self::LIST_FRAME]);

        self::assertResponseIsSuccessful();

        $navigated = $this->title((string) $client->getResponse()->getContent());

        self::assertNotSame('', $navigated, 'no title in the fragment leaves the tab showing the raw URL');

        // The same sentence the full page would have put there, computed the
        // same way — the inbox's title carries its unread count, so this is
        // also the assertion that the fragment still works that count out
        // rather than shipping a constant.
        self::assertSame(
            $this->title($this->page($client, '/mail/inbox')),
            $navigated,
            'the navigated title and the visited title have to be the same sentence, or the tab lies',
        );
    }

    /**
     * A title that is computed rather than constant still comes out right,
     * which is the case a fragment layout with a hardcoded title would have
     * got wrong: search says "Results for x" with a query and "Search" without
     * one.
     */
    public function testAConditionalTitleIsStillTheRightOneInAFragment(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/search?q=quarterly', server: [self::TURBO_FRAME_HEADER => self::LIST_FRAME]);
        $withQuery = $this->title((string) $client->getResponse()->getContent());

        $client->request('GET', '/mail/search', server: [self::TURBO_FRAME_HEADER => self::LIST_FRAME]);
        $without = $this->title((string) $client->getResponse()->getContent());

        self::assertStringContainsString('quarterly', $withQuery);
        self::assertNotSame($withQuery, $without);
    }

    /**
     * Only THIS frame. Every other frame on a mail page — the compose dock, the
     * modal, the calendar pane, an account's folder list — navigates on its own
     * and needs the document it asked for.
     */
    public function testAnotherFramesNavigationStillGetsTheWholePage(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: [self::TURBO_FRAME_HEADER => 'compose_dock']);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="sidebar"', $body, 'the gate is on one frame id, not on the header');
    }

    // ── the sidebar is on four pages and only one has the frame ───────────

    /**
     * No page may ship a link claiming `inbox-list-frame` unless that frame is
     * on it.
     *
     * `_partials/_sidebar.html.twig` is included by four layouts — the mailbox,
     * admin, calendar and settings — and only the mailbox has the frame.
     * v0.2.34 put a real `data-turbo-frame="inbox-list-frame"` on ten folder
     * rows in that shared partial, so the other three shipped rows pointing at
     * a frame that was nowhere on the page.
     *
     * The consequence is not the harmless fallback it reads as. Turbo
     * PREFETCHES a link on hover and sends `Turbo-Frame` on the prefetch of a
     * frame-targeted one WITHOUT checking the frame exists, so the server
     * answers with the fragment; the click then finds no frame, falls back to
     * an ordinary visit, and that visit takes the prefetched fragment out of a
     * URL-keyed cache without asking again. The fragment renders as the whole
     * document: right URL, no sidebar, no topbar, a bare message list.
     *
     * Every assertion in this file passed while that was live, because every
     * one of them is about /mail/*, where the claim is true. This is the one
     * that looks at the pages where it is not — and it is a string assertion
     * about markup precisely because the failure had no server-side symptom at
     * all.
     */
    public function testNoPageWithoutTheListFrameClaimsIt(): void
    {
        $client = $this->signedIn();

        foreach (['/admin', '/calendar', '/settings'] as $uri) {
            $client->request('GET', $uri);

            self::assertResponseIsSuccessful(sprintf('%s did not render', $uri));

            $body = (string) $client->getResponse()->getContent();

            // The premise. If one of these ever grows a list frame of its own
            // the test below stops meaning anything, and this says so loudly
            // rather than passing on.
            self::assertStringNotContainsString(
                'id="' . self::LIST_FRAME . '"',
                $body,
                sprintf('%s has grown a list frame — the assertion below no longer applies', $uri),
            );

            self::assertStringNotContainsString(
                'data-turbo-frame="' . self::LIST_FRAME . '"',
                $body,
                sprintf(
                    '%s ships a link claiming a frame it does not have; Turbo prefetches that '
                    . 'claim on hover and the click renders the chrome-less answer as the page',
                    $uri,
                ),
            );
        }
    }

    /**
     * The other half: a mail page still ships rows the sidebar controller can
     * promote, or the saving above is gone and nothing would say so.
     *
     * The rows carry an inert marker and `ui--sidebar#_bindListFrame` turns it
     * into the real attribute after finding the frame in the live DOM — which
     * is why this asserts the marker rather than `data-turbo-frame`, and why
     * the journey itself is pinned in tests/e2e/list-frame-navigation.spec.ts
     * where there is a browser to do the promoting.
     */
    public function testAMailPageShipsRowsTheSidebarCanPointAtTheList(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="' . self::LIST_FRAME . '"', $body);
        self::assertStringContainsString(
            'data-list-frame',
            $body,
            'the folder rows carry no marker, so nothing will point them at the list',
        );
    }

    /**
     * One URL, three documents, so every one of them says which headers chose
     * it.
     *
     * On both representations deliberately: `Vary` on the fragment alone still
     * lets a cache hand a stored full page to a frame request. See
     * App\Twig\ListFragmentGlobal for the two measured routes by which a
     * browser serves the wrong one of these back.
     */
    public function testAListUrlSaysWhichHeadersChooseItsRepresentation(): void
    {
        $client = $this->signedIn();

        foreach ([[], [self::TURBO_FRAME_HEADER => self::LIST_FRAME]] as $server) {
            $client->request('GET', '/mail/inbox', server: $server);

            $vary = (string) $client->getResponse()->headers->get('Vary');

            self::assertStringContainsString('Turbo-Frame', $vary);
            self::assertStringContainsString('X-List-Fragment', $vary);
        }
    }

    // ── the flag that stops Back showing an empty list ────────────────────

    public function testAListPageMarksItsFrameAsRendered(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        self::assertStringContainsString(
            'data-list-rendered="1"',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testTheTitleNamesTheCountItShows(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        self::assertStringContainsString(
            'name="title-count-key" content="role:inbox"',
            (string) $client->getResponse()->getContent(),
            'the sidebar rewrites the title from this key after a sync',
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function page(KernelBrowser $client, string $uri): string
    {
        $client->request('GET', $uri);

        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    private function title(string $html): string
    {
        preg_match('#<title>(.*?)</title>#s', $html, $matches);

        return trim($matches[1] ?? '');
    }

    /**
     * The head elements Turbo compares one document against another by: every
     * title, meta and link, in document order.
     *
     * Scripts are deliberately not among them. Turbo treats those as a category
     * of their own — it appends the ones the response has and the document does
     * not, rather than removing the ones it lacks — and the inline script in our
     * head carries a per-request CSP nonce, so comparing it would fail on a
     * difference that has no consequence.
     *
     * The csrf token is masked for a related reason, and it is worth knowing
     * about: `csrf_token()` re-randomises its output on every call, so no two
     * renders of the same page agree on that meta's content even though both
     * are valid for the same session. Turbo therefore swaps one live token for
     * another on every frame navigation, and has always done so — including
     * before any of this, when navigations were answered with the whole page.
     * That the meta is THERE is what matters, and the test above says so.
     *
     * @return list<string>
     */
    private function headElements(string $html): array
    {
        if (1 !== preg_match('#<head>(.*?)</head>#s', $html, $head)) {
            return [];
        }

        preg_match_all('#<title>.*?</title>|<(?:meta|link)\b[^>]*>#is', $head[1], $elements);

        return array_map(
            static function (string $element): string {
                $element = (string) preg_replace('/\s+/', ' ', $element);

                return (string) preg_replace(
                    '/(name="csrf-token" content=")[^"]*/',
                    '$1…',
                    $element,
                );
            },
            $elements[0],
        );
    }
}
