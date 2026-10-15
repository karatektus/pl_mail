import { test, expect } from "./support/test";
import { ajaxPost, seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";
import { setDeleteNow } from "./support/disposal";

/**
 * The whole of Spam is deleted for good in one go, not a page at a time.
 *
 * WHY THIS HAS TO BE A BROWSER TEST
 * ─────────────────────────────────
 * BulkPurgeTest has the request starting a job for Spam and the bin and being
 * refused for every other view, and RunBulkStatusHandlerTest has what the job
 * deletes and what it leaves. What neither can see is the page's part: that
 * Delete forever acts on a selection extended past the page at all — it used to
 * return without a word — and that the question it asks first names the whole
 * view's count and not the fifty rows on screen.
 *
 * More than one page of mail, for the reason bulk-select-all.spec.ts gives: it
 * is the only state in which "the page" and "the view" differ.
 */
const TOOLBAR = '[data-controller="mail--list-toolbar"]';
const BANNER = '[data-mail--list-toolbar-target="viewBanner"]';

test.beforeAll(() => {
    seed("seed-bulk --clear");
});

test.beforeEach(async ({ page }) => {
    seed("seed-bulk --messages=200");

    await setDeleteNow(page, true);
});

test.afterEach(async ({ page }) => {
    await setDeleteNow(page, false);
});

// The bulk account away again, and the worker's own mailbox put back: this
// moves the whole inbox to Spam and deletes it, and `seed-mail` wipes and
// reseeds. See bulk-purge.spec.ts for what leaving it costs the next file.
test.afterAll(() => {
    seed("seed-bulk --clear");
    seed("seed-mail");
});

test("Delete forever above a whole-view selection empties Spam, and asks about all of it first", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(page.locator("#message-list li").first()).toBeVisible();

    // Spam made the way the page makes it: the whole inbox view moved there.
    const value = (await page.locator(TOOLBAR).getAttribute("data-mail--list-toolbar-view-value-value")) ?? "";
    const moved = await ajaxPost(page, "/status/bulk/move-to", { all: true, role: "spam", scope: "inbox", value });

    expect(moved.ok(), "moving the inbox to Spam failed").toBe(true);

    // The move is a background job. Spam is ready once it holds more than the
    // page shows, which is also the state this test needs.
    const total = async () => {
        await page.goto("/mail/spam");

        return Number(await page.locator(TOOLBAR).getAttribute("data-mail--list-toolbar-total-value"));
    };

    await expect.poll(total, { timeout: 30_000, message: "Spam never filled past one page" }).toBeGreaterThan(50);

    // Settled: the same total twice running, so the count the question is
    // checked against below is the finished move's and not one in flight.
    let inSpam = await total();

    await expect
        .poll(async () => {
            const previous = inSpam;

            inSpam = await total();

            return inSpam === previous;
        }, { timeout: 30_000 })
        .toBe(true);

    const rows = await page.locator("#message-list li").count();

    expect(inSpam, "this fixture does not span more than one page").toBeGreaterThan(rows);

    await page.locator(`${TOOLBAR} [role="checkbox"]`).first().click();
    await page.locator(BANNER).getByRole("button").click();

    await page.locator(TOOLBAR).getByRole("button", { name: "Delete forever" }).click();

    const dialog = page.locator("#confirm-dialog");

    await expect(dialog).toBeVisible();
    await expect(dialog, "the question is about the whole view, not the page").toContainText(`these ${inSpam} conversations`);

    await acceptConfirm(page);

    await expect.poll(total, { timeout: 30_000, message: "Spam was not emptied" }).toBe(0);

    // Deleted, not moved: the bin did not receive what left Spam.
    await page.goto("/mail/trash");

    const inBin = Number(await page.locator(TOOLBAR).getAttribute("data-mail--list-toolbar-total-value"));

    expect(inBin, "the mail went to the bin instead of being deleted").toBeLessThan(inSpam);
});
