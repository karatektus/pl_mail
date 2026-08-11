import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * An idle inbox should be close to silent.
 *
 * The reported measurement was four requests per second sitting on the inbox
 * doing nothing: /mail/sidebar/counts thirty-two times in ten seconds, and
 * /mail/inbox — eighty kilobytes of HTML — eight times. Three causes, all fixed
 * in the same change: the sidebar partial is included twice so both instances
 * fetched the counts, a sync run's burst of one event per mailbox per account
 * was serialised into one request each, and the list refresh asked for the whole
 * page in order to use one frame of it.
 *
 * This measures the thing that was reported rather than the mechanisms, because
 * the mechanisms are where the next regression will come from too — a third
 * sidebar, a new event, some other listener — and a bound on requests catches
 * all of them.
 *
 * The window is generous on purpose. The point is the order of magnitude: 40
 * requests in ten seconds against a handful. A tight bound here would fail on a
 * loaded machine for reasons that have nothing to do with polling.
 */

/** How long to sit still and count. */
const IDLE_MS = 10_000;

/**
 * What an idle page may ask for in that window.
 *
 * Above one, because a first refresh right after load is legitimate — the
 * MIN_REFRESH_MS floor permits a leading request and then one trailing one.
 * Far below the eight and thirty-two that were measured.
 */
const MAX_LIST_REQUESTS = 2;
const MAX_COUNTS_REQUESTS = 2;

test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("idle polling", () => {
    test("an idle inbox does not poll four times a second", async ({ page }) => {
        const listRequests: string[] = [];
        const countsRequests: string[] = [];

        page.on("request", (request) => {
            const url = request.url();

            if (url.includes("/mail/sidebar/counts")) {
                countsRequests.push(url);

                return;
            }

            // The list refresh asks for the page's own URL, so it is matched by
            // path rather than by anything distinguishing — which is the point:
            // any repeat fetch of the list URL counts, however it was caused.
            if (url.includes("/mail/inbox")) {
                listRequests.push(url);
            }
        });

        await page.goto("/mail/inbox");
        await expect(page.locator("#inbox-list-frame")).toBeVisible();

        // The navigation itself is not polling.
        listRequests.length = 0;
        countsRequests.length = 0;

        await page.waitForTimeout(IDLE_MS);

        expect(
            listRequests.length,
            `list refreshes while idle for ${IDLE_MS}ms (was 8 before the fix)`,
        ).toBeLessThanOrEqual(MAX_LIST_REQUESTS);

        expect(
            countsRequests.length,
            `counts fetches while idle for ${IDLE_MS}ms (was 32 before the fix)`,
        ).toBeLessThanOrEqual(MAX_COUNTS_REQUESTS);
    });

    /**
     * Nobody is looking, so nothing should be asked. A backgrounded tab used to
     * poll exactly as hard as a foregrounded one, which on a phone is battery
     * spent on a list no one can see.
     */
    test("a hidden tab asks for nothing at all", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#inbox-list-frame")).toBeVisible();

        // Backgrounding a tab for real is not available here. Playwright has no
        // visibility API; opening a second tab and calling bringToFront() leaves
        // document.hidden false in headless Chromium, so a test written that way
        // asserts nothing; and Emulation.setPageVisibilityOverride, which used
        // to do exactly this, has been removed from Chromium's protocol.
        //
        // So the signal is supplied rather than the situation: the page is made
        // to report itself hidden and told so, which is precisely what the
        // browser does to a backgrounded tab and precisely what the controllers
        // read. What this does not prove is that the browser sets the flag —
        // that is the browser's business, not ours. What it does prove is the
        // part that was wrong: that our pollers stop when it is set.
        await page.evaluate(() => {
            Object.defineProperty(document, "visibilityState", {
                configurable: true,
                get: () => "hidden",
            });
            Object.defineProperty(document, "hidden", {
                configurable: true,
                get: () => true,
            });
            document.dispatchEvent(new Event("visibilitychange"));
        });

        await expect
            .poll(() => page.evaluate(() => document.hidden), {
                message: "the tab has to actually be hidden for this to prove anything",
                timeout: 5000,
            })
            .toBe(true);

        const whileHidden: string[] = [];

        page.on("request", (request) => {
            const url = request.url();

            if (url.includes("/mail/sidebar/counts") || url.includes("/mail/inbox")) {
                whileHidden.push(url);
            }
        });

        await page.waitForTimeout(IDLE_MS);

        expect(whileHidden, "a hidden tab must not poll").toEqual([]);
    });

    /**
     * The refresh that does happen fetches the list frame, not the document it
     * lives in. Asserted through the response body rather than its size, since
     * a fixture inbox is small enough that a byte count proves little.
     */
    test("the list refresh asks for the frame, not the whole page", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#inbox-list-frame")).toBeVisible();

        const fragment = await page.evaluate(async () => {
            const response = await fetch(window.location.href, {
                headers: { "X-List-Fragment": "inbox-list-frame", Accept: "text/html" },
                credentials: "same-origin",
            });

            return response.text();
        });

        expect(fragment).toContain('id="inbox-list-frame"');
        expect(fragment).not.toContain("<html");
        expect(fragment).not.toContain('id="sidebar"');
    });

    /**
     * The debug line that was shipping to production.
     */
    test("nothing logs to the console on an idle inbox", async ({ page }) => {
        const logs: string[] = [];

        page.on("console", (message) => {
            if ("log" === message.type()) {
                logs.push(message.text());
            }
        });

        await page.goto("/mail/inbox");
        await expect(page.locator("#inbox-list-frame")).toBeVisible();
        await page.waitForTimeout(3000);

        expect(logs.filter((line) => line.includes("[mail-pane]"))).toEqual([]);
    });
});
