import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The list the browser's Back button shows is not one from before the read.
 *
 * THE REPORTED BUG
 * ────────────────
 * Open a mail, leave it a few seconds, press Back — the row is bold again,
 * still unread, still counted, until the page is reloaded by hand.
 *
 * Opening a conversation is a Turbo Drive visit, so Turbo snapshots the list on
 * the way out — and it leaves BEFORE the read happens, because mail--thread-read
 * waits two seconds and then posts. The stream that post renders back targets a
 * row that is not on the thread page, so the snapshot is never corrected
 * either. Back restored a picture of the list taken before the read, with no
 * request made that could have known better. The mail lists are no longer
 * snapshotted (see _layout/_mailbox.html.twig), so Back re-fetches.
 *
 * TWO WAYS THIS TEST WAS WORTHLESS BEFORE IT WORKED, both worth keeping:
 *
 *   1. `toHaveAttribute` RETRIES for five seconds. A poll repairs the list a
 *      moment after Back — which is why the report says "sometimes" — so the
 *      ordinary assertion waits for the repair and reports success about a list
 *      the user never saw. The read below is one-shot for that reason.
 *
 *   2. CLICKING A ROW DOES NOT NAVIGATE at this viewport. It swaps the reading
 *      pane in and leaves the URL on /mail/inbox, so page.goBack() went
 *      somewhere else entirely and the whole sequence proved nothing. The visit
 *      has to be a real one, which is what Turbo.visit() below makes it.
 *
 * Both were verified by disabling the fix: with it off this test reports
 * `data-unread="true"`, and with it on, `"false"`.
 */

/** mail--thread-read's own delay, plus room for the post to land. */
const READ_SETTLED_MS = 3500;

test.beforeEach(() => {
    seed("seed-mail");
});

test("the browser's back button does not show a list from before the read", async ({ page }) => {
    await page.goto("/mail/inbox");

    const row = mailRow(page, INBOX_SUBJECTS.read);

    await expect(row).toBeVisible();
    await expect(row, "the fixture has to start unread or this proves nothing").toHaveAttribute(
        "data-unread",
        "true",
    );

    const href = await row.locator("a[href*='/mail/thread/']").first().getAttribute("href");

    expect(href, "the row has to link somewhere for this to be a navigation").toBeTruthy();

    // A real Drive visit, because a click here only swaps the pane — see (2).
    await page.evaluate(
            (target) => (window as unknown as { Turbo: { visit(u: string): void } }).Turbo.visit(target),
            href as string,
        );
    await page.waitForURL("**/mail/thread/**");

    // Long enough that the read has definitely been recorded. The reported case
    // is a mail left open "a few seconds"; under two there is nothing to lose.
    await page.waitForTimeout(READ_SETTLED_MS);

    await page.goBack();
    await page.waitForURL("**/mail/inbox**");

    const restored = mailRow(page, INBOX_SUBJECTS.read);

    // Wait for the row to EXIST, and for nothing else.
    await restored.waitFor({ state: "attached" });

    // Read once — see (1).
    const unread = await restored.getAttribute("data-unread");

    expect(unread, "back served a list snapshotted before the read").toBe("false");
});
