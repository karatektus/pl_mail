import { Controller } from '@hotwired/stimulus';

/**
 * A `mailto:` link handed to the INSTALLED app, opened in the window that is
 * already open (#585).
 *
 * The manifest's `launch_handler` asks for `focus-existing`: when plMail runs
 * as an installed app and the system gives it a link, Chromium brings the open
 * window to the front instead of opening another one — and then does nothing
 * else. It does not navigate. The link arrives as LaunchParams on
 * `window.launchQueue`, and a page with no consumer simply gets focused. This
 * is that consumer: the link's compose URL goes into the dock, the way a
 * mailto: clicked inside a message does (mail--message-frame#openMailto).
 *
 * What it cannot do is the same for a pinned TAB. A browser's protocol handler
 * always opens a new tab, and no page can bring another tab to the front —
 * `WindowClient.focus()` from the service worker is only allowed in answer to
 * a notification click. So the installed app is the one place this works.
 *
 * Every launch comes through here, not only links:
 *   - the app icon: targetURL is the start URL, which is no compose URL and is
 *     left alone;
 *   - the FIRST launch of a link, with no window open yet: Chromium navigates
 *     to /compose/new?mailto=…, the server redirects that to the mailbox with
 *     `?compose=` (ComposeController::renderWindow), the dock already loads it
 *     from there — and this page then receives the same launch. Opening it
 *     again would replace the window with a second copy of itself, so a launch
 *     that names what `?compose=` already opened is skipped.
 *
 * Mounted on the dock wrapper beside compose--dock. That element is rendered
 * again on every Turbo visit, so connect() runs again and sets the consumer
 * again; the queue delivers each launch once, so nothing is replayed. The dock
 * frame is looked up when a launch arrives rather than kept, for the same
 * reason.
 */
export default class extends Controller {
    connect() {
        if (!('launchQueue' in window) || typeof window.launchQueue?.setConsumer !== 'function') {
            return;
        }

        window.launchQueue.setConsumer((params) => this.#launched(params));
    }

    #launched(params) {
        const target = this.#composeUrl(params?.targetURL);

        if (null === target || this.#alreadyOpened(target)) {
            return;
        }

        const url  = target.pathname + target.search;
        const dock = document.querySelector('turbo-frame#compose_dock');

        if (null === dock) {
            window.location.assign(url);
            return;
        }

        // The same link twice leaves `src` as it was, and a frame told to load
        // the URL it already has does not fetch it again.
        if (dock.getAttribute('src') === url) {
            dock.reload();
            return;
        }

        dock.src = url;
    }

    /**
     * The launch's URL when it is one of our own compose URLs, null for
     * anything else. The same rule the layout applies to `?compose=`: our
     * origin, a path under /compose/ — the value becomes a `src` this page
     * fetches with the session attached.
     */
    #composeUrl(href) {
        if (typeof href !== 'string' || '' === href) {
            return null;
        }

        let url;

        try {
            url = new URL(href, window.location.href);
        } catch {
            return null;
        }

        if (url.origin !== window.location.origin || !url.pathname.startsWith('/compose/')) {
            return null;
        }

        return url;
    }

    /**
     * Whether this page was loaded to open exactly this URL: compared by path
     * and parameters rather than as text, because the redirect re-encodes the
     * query (http_build_query) and the browser's `%s` encodes it its own way.
     */
    #alreadyOpened(target) {
        const compose = new URLSearchParams(window.location.search).get('compose');

        if (null === compose) {
            return false;
        }

        let opened;

        try {
            opened = new URL(compose, window.location.origin);
        } catch {
            return false;
        }

        return opened.pathname === target.pathname && this.#same(opened.searchParams, target.searchParams);
    }

    #same(a, b) {
        const flat = (params) => JSON.stringify([...params.entries()].sort());

        return flat(a) === flat(b);
    }
}
