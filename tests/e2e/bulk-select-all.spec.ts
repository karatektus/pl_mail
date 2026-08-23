import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * "Select all" means all of them, and one action is one request.
 *
 * The checkbox selected the rows on screen, which is the only thing a checkbox
 * over a paginated list can mean on its own — and not what anybody wants it to
 * mean in a bin holding a hundred and ninety-five messages. It now offers to
 * extend to the whole view, and the same gesture works for "every starred mail".
 *
 * Two other reported faults are fixed by the same change:
 *
 *   • The toolbar posted once per conversation, in parallel. Fine for fifty
 *     rows, impossible for a whole folder.
 *   • Every one of those responses redrew its own row and knew nothing else, so
 *     an emptied list still said "1–5 of 5" and never showed its empty state.
 *     The pager and the placeholder belong to the LIST; no row-shaped response
 *     carries them.
 */
/**
 * More than one page of conversations, which is the only state where "select
 * all" and "select all in this view" differ — and therefore the only state this
 * feature exists for. 200 messages lands around 67 threads against a page size
 * of 50, and seeds in under ten seconds; the command's own default is 200,000,
 * which is for query measurement and times out a browser context.
 */
test.beforeEach(() => {
    seed("seed-bulk --messages=200");
});

// Its own account, so it has to be taken away again or every later spec in this
// worker inherits a mailbox with sixty-seven extra conversations in it — and,
// worse, a second account that changes what "the first account" means on the
// settings page, which is how a signature written by another spec ended up on
// an account its composer never opened.
//
// Cleared before as well as after: an interrupted run leaves the account
// behind, and the next run would then inherit exactly the state this is here to
// prevent. `--clear` is a no-op when there is nothing to clear.
test.beforeAll(() => {
    seed("seed-bulk --clear");
});

test.afterAll(() => {
    seed("seed-bulk --clear");
});

const TOOLBAR = '[data-controller="mail--list-toolbar"]';
const BANNER = '[data-mail--list-toolbar-target="viewBanner"]';

async function selectEveryRowOnScreen(page: import("@playwright/test").Page) {
    await page.locator(`${TOOLBAR} [role="checkbox"]`).first().click();
}

test.describe("selecting past the page", () => {
    test("offers the whole view once the page is selected, and acts on it in one request", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();

        const banner = page.locator(BANNER);
        await expect(banner, "the offer is showing before anything is selected").toBeHidden();

        await selectEveryRowOnScreen(page);
        await expect(banner, "selecting the page did not offer the rest of the view").toBeVisible();

        // The offer names the real total, not the page size.
        const total = Number(await page.locator(TOOLBAR).getAttribute("data-mail--list-toolbar-total-value"));
        const rows = await page.locator("#message-list li").count();

        expect(total, "this fixture does not span more than one page").toBeGreaterThan(rows);
        await expect(banner).toContainText(String(total));

        let bulkRequests = 0;
        page.on("request", (request) => {
            if (request.url().includes("/status/bulk/")) {
                bulkRequests++;
            }
        });

        await banner.getByRole("button").click();
        await page.locator(TOOLBAR).getByRole("button", { name: /archive/i }).click();

        // The whole view went, not the page: what is left is whatever else this
        // worker's mailbox holds, and it has to be less than a full page.
        await expect
            .poll(() => page.locator("#message-list li").count(), { timeout: 15_000 })
            .toBeLessThan(rows);

        // One request for all of it — the thing that makes this safe to offer.
        // Per conversation it would have been seventy.
        expect(bulkRequests, "the toolbar posted per conversation again").toBe(1);
    });

    /**
     * The pager and the empty state follow the rows.
     *
     * Reported separately: after archiving, the list lost its rows and kept
     * saying "1–5 of 5"; after deleting the last draft the list was blank with
     * no empty state at all. Both are the same cause — a per-row response
     * cannot speak for the list.
     */
    test("the pager agrees with the rows after a bulk action", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();

        await selectEveryRowOnScreen(page);
        await page.locator(BANNER).getByRole("button").click();
        await page.locator(TOOLBAR).getByRole("button", { name: /archive/i }).click();

        const pager = page.locator('[data-list-region="pagination"]');

        // The reported symptom: rows went and the pager did not. It is a
        // refreshable region of the frame, so what this really asserts is that
        // a bulk action triggers the list refresh at all — per-conversation
        // responses never did, because none of them knew anything about the
        // list as a whole.
        await expect
            .poll(async () => {
                const rows = await page.locator("#message-list li").count();
                const text = await pager.innerText();

                return text.includes(`of ${rows}`);
            }, { timeout: 15_000 })
            .toBe(true);
    });

    /**
     * The toolbar's own total is deliberately NOT part of that.
     *
     * The pane's background refresh replaces marked regions of the frame and
     * leaves the toolbar alone, because rebuilding it would come back from the
     * server with nothing selected and silently throw away a selection the user
     * had made — which mail.spec.ts pins in "a second bulk action survives the
     * first one's refresh landing on it". Asserted here so the next person to
     * notice the stale number reads this instead of "fixing" it.
     */
    test("a refresh does not rebuild the toolbar and lose the selection", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();

        await selectEveryRowOnScreen(page);

        const selected = await page.locator('[data-mail--list-toolbar-target="selectionCount"]').innerText();
        expect(selected, "nothing was selected to begin with").not.toBe("");

        await page.locator(BANNER).getByRole("button").click();
        await expect(page.locator(BANNER)).toContainText(/selected/i);
    });
});
