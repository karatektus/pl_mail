import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, TEST_ADMIN, login, mailRow, seed, seedUser } from "./support/config";

/**
 * Opening a folder swaps the list and leaves the page around it alone.
 *
 * The sidebar's rows, the category tabs, the pager and the search box all
 * navigate one frame — `inbox-list-frame` — and the server answers a request
 * carrying Turbo's `Turbo-Frame` header with the document stripped to that
 * frame: no sidebar, no topbar, no calendar pane, no reading pane. Measured at
 * 21–24 database statements a page down to 9–13 (see ThreadListQueryBudgetTest).
 *
 * WHAT THIS FILE IS ACTUALLY FOR is the other half of that sentence: the parts
 * of the page the answer no longer carries have to keep working anyway, and
 * every one of them fails silently rather than loudly.
 *
 *   - The tab title comes from the answer's <title>, and only if the rest of
 *     that head is there too: Turbo compares the `data-turbo-track="reload"`
 *     elements first and quietly renders nothing when they differ. Serving the
 *     frame with a title and no importmap was measured leaving the tab on the
 *     folder you left, with no error anywhere.
 *   - The head is then MERGED rather than added to: Turbo removes from the live
 *     document every meta the answer leaves out. The csrf token and the
 *     cache-control the back button depends on both live there, and losing
 *     either shows up somewhere else entirely, hours later.
 *   - The sidebar's highlight moves on `turbo:load`, which a frame navigation
 *     does not fire.
 *
 * None of that is visible in the list, which is why it is asserted here rather
 * than trusted to the specs that click through folders for other reasons.
 */

const ROWS = '#message-list li[data-controller="mail--message-row"]';

test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("navigating the list frame", () => {
    /**
     * One click, and everything that is supposed to follow it.
     *
     * Written as one test rather than six because it is one gesture: the
     * request that goes out, the list that comes back, the URL, the tab and the
     * sidebar are five readings of the same click, and splitting them would be
     * five fixtures and five clicks to assert one thing happened properly.
     */
    test("a folder click fetches the frame and the page keeps up with it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        const titleBefore = await page.title();

        // What actually goes on the wire. The header is the whole gate — see
        // App\Twig\ListFragmentGlobal — so a link that quietly stopped being a
        // frame navigation would still pass every assertion below except this.
        const request = page.waitForRequest(
            (candidate) =>
                candidate.url().includes("/mail/sent") &&
                candidate.headers()["turbo-frame"] === "inbox-list-frame",
        );

        await page.locator("#sidebar a[href='/mail/sent']").first().click();
        await request;

        // The list is the Sent folder's, the address bar agrees, and so does
        // the tab — which it can only do if the answer carried a <title>.
        await expect(page).toHaveURL(/\/mail\/sent/);
        await expect(page.locator("#message-list")).toBeVisible();
        await expect.poll(() => page.title()).not.toBe(titleBefore);
        expect(await page.title()).toMatch(/sent/i);

        // The sidebar is not re-rendered by a frame navigation, so its
        // highlight has to move by itself or it goes on pointing at the inbox.
        await expect(
            page.locator("#sidebar a[href='/mail/sent']").first()
                .locator("xpath=ancestor-or-self::*[contains(@class,'nav-item')][1]"),
        ).toHaveClass(/is-active/);

        // The head survived the merge. Both of these are read at runtime by
        // something that is not on screen: the token by every fetch-based
        // write, the cache-control by the back button.
        await expect(page.locator('head meta[name="csrf-token"]')).toHaveCount(1);
        await expect(page.locator('head meta[name="turbo-cache-control"]')).toHaveCount(1);
    });

    /**
     * Back after a frame navigation, which is the hazard the advance introduces:
     * the frame pushes a history entry, and nothing else on the page moved, so
     * the entry has to be able to reconstruct the list on its own.
     */
    test("back returns to the folder it came from, list and highlight together", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        const inboxRows = await page.locator(ROWS).count();

        await page.locator("#sidebar a[href='/mail/sent']").first().click();
        await expect(page).toHaveURL(/\/mail\/sent/);

        await page.goBack();

        await expect(page).toHaveURL(/\/mail\/inbox/);
        await expect(page.locator(ROWS)).toHaveCount(inboxRows);
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        await expect(
            page.locator("#sidebar a[href='/mail/inbox']").first()
                .locator("xpath=ancestor-or-self::*[contains(@class,'nav-item')][1]"),
        ).toHaveClass(/is-active/);
    });

    /**
     * A row click is not a Turbo navigation at all — mail--mail-pane#open
     * preventDefaults it and fetches the conversation into the pane — so the
     * frame gate must not have come anywhere near it. Asserted because the
     * reading pane lives OUTSIDE the frame, which is exactly the sort of thing
     * that survives a change like this by luck rather than by design.
     */
    test("opening a conversation still swaps the pane rather than the frame", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        await mailRow(page, INBOX_SUBJECTS.read).click();

        await expect(page.locator("#message-list")).toBeHidden();
        await expect(page.getByRole("button", { name: "Back" }).first()).toBeVisible();
    });
});

/**
 * The same sidebar, on a page that has no list frame at all.
 *
 * `_partials/_sidebar.html.twig` is included by four layouts and only the
 * mailbox has `inbox-list-frame`; admin, calendar and settings do not. v0.2.34
 * put the real `data-turbo-frame` on the folder rows of that shared partial, so
 * on those three every row claimed a frame that was nowhere on the page, and
 * clicking Inbox from /admin rendered the chrome-less list answer AS THE WHOLE
 * DOCUMENT — right URL, no sidebar, no topbar.
 *
 * NOTHING SERVER-SIDE COULD HAVE CAUGHT IT and nothing in the describe block
 * above would either, because both only ever start on a mail page, where the
 * claim is true. The bug needs a real browser and a real mouse: the damage is
 * done by Turbo's hover PREFETCH, which sends `Turbo-Frame` without checking
 * the frame exists, and by the click then reusing that prefetched fragment out
 * of a URL-keyed cache instead of making a request of its own. Click without
 * hovering first and the same row behaves perfectly, which is exactly how this
 * got through a release.
 *
 * Hence the deliberate hover-then-pause below. It is not flake-padding; it is
 * the reproduction.
 */
test.describe("reaching a mail list from a page that has no list frame", () => {
    // /admin needs ROLE_ADMIN, and granting it to the shared e2e user mid-run
    // would deauthenticate every other spec's session — Symfony treats a token
    // whose roles changed as stale. Same reasoning as admin-panels.spec.ts.
    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_ADMIN.email, password: TEST_ADMIN.password, admin: true });
    });

    test("clicking Inbox from the admin page lands on a whole page, not a bare list", async ({ page }) => {
        await login(page, TEST_ADMIN.email, TEST_ADMIN.password);

        await page.goto("/admin");
        await expect(page.locator("#sidebar")).toBeVisible();

        const inbox = page.locator("#sidebar a[href='/mail/inbox']").first();

        // Long enough for Turbo to start and finish the prefetch, so the click
        // has something cached to consume. Without this the click issues its
        // own request and the bug does not appear at all.
        await inbox.hover();
        await page.waitForTimeout(500);

        await inbox.click();

        await expect(page).toHaveURL(/\/mail\/inbox/);

        // THE ASSERTION. All three were missing from the reported screenshot,
        // and the message list was there — so asserting the list alone would
        // have passed against the bug.
        await expect(page.locator("#sidebar")).toBeVisible();
        await expect(page.locator("#message-list")).toBeVisible();
        await expect(page.locator("header").first()).toBeVisible();

        // And now that there IS a list frame on the page, the rows are allowed
        // to point at it again — ui--sidebar#_bindListFrame promotes the marker
        // once it finds the frame. This is the half that keeps v0.2.34's
        // saving; without it the fix would just be a revert.
        await expect(
            page.locator("#sidebar a[href='/mail/sent']").first(),
        ).toHaveAttribute("data-turbo-frame", "inbox-list-frame");
    });

    /**
     * The markup half, asserted where a browser can see it: on a page with no
     * list frame, no sidebar row may claim one — however it got there.
     */
    test("the admin sidebar claims no frame the admin page does not have", async ({ page }) => {
        await login(page, TEST_ADMIN.email, TEST_ADMIN.password);

        await page.goto("/admin");
        await expect(page.locator("#sidebar")).toBeVisible();

        await expect(page.locator('turbo-frame#inbox-list-frame')).toHaveCount(0);
        await expect(page.locator('[data-turbo-frame="inbox-list-frame"]')).toHaveCount(0);
    });
});
