import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

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
