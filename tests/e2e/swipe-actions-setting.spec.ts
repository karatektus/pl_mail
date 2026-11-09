import { test, expect, devices, type Locator, type Page } from "./support/test";
import { Finger } from "./support/touch";
import { settled } from "./support/motion";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { setSwipeAction } from "./support/swipe_actions";

/**
 * The left swipe does what Settings → Mail → Swipe actions says it does.
 *
 * Left is set to Star through the page, then a full left drag on an unstarred
 * row in the Inbox must post to the star route and redraw the row starred, and
 * must not post to archive. The default (archive) is pinned by row-swipe.spec.ts,
 * which is the companion: the same drag there archives. Left set to Delete
 * (#527) is the second case: the drag trashes, and the toast's Undo puts it back.
 *
 * Touch only, below md, so the phone device is the one that can swipe at all.
 */
test.use({ ...devices["Pixel 5"] });

const FULL = 0.85;
const LEFT_START = 0.9;
const DROP_PAUSE_MS = 150;

test.beforeEach(() => {
    seed("seed-mail");
});

test.afterEach(async ({ page }) => {
    await setSwipeAction(page, "Swipe left", "Archive");
});

/** A full left drag from near the right edge, released after a pause so only the full threshold can commit it. */
async function fullLeftDrag(page: Page, row: Locator): Promise<void> {
    const box = await row.boundingBox();

    expect(box, "the row is rendered").not.toBeNull();

    const finger = await Finger.down(page, box!.x + box!.width * LEFT_START, box!.y + box!.height / 2);

    await finger.move(-FULL * box!.width, 0);
    await page.waitForTimeout(DROP_PAUSE_MS);
    await finger.up();
}

test("with left set to Star, a full left drag stars the row and does not archive it", async ({ page }) => {
    await setSwipeAction(page, "Swipe left", "Star");

    await page.goto("/mail/inbox");
    await settled(page);

    const row = mailRow(page, INBOX_SUBJECTS.archive);

    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute("data-starred", "false");

    const id = ((await row.getAttribute("id")) ?? "").replace("thread_", "");
    const archived: string[] = [];

    page.on("request", (request) => {
        if (request.method() === "POST" && new URL(request.url()).pathname.endsWith("/archive")) {
            archived.push(request.url());
        }
    });

    const starred = page.waitForRequest(
        (request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/star`,
    );

    await fullLeftDrag(page, row);

    await starred;

    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-starred", "true");
    await expect(mailRow(page, INBOX_SUBJECTS.archive), "the row stays in the Inbox").toBeVisible();

    expect(archived, "no archive request was sent").toEqual([]);
});

/**
 * Delete is a choice since #527. A full left drag moves the row to the bin, with
 * nothing in front of it, so the toast's Undo is what makes that safe: it is
 * pressed here and the row must come back to the Inbox.
 */
test("with left set to Delete, a full left drag moves the row to the bin and Undo brings it back", async ({ page }) => {
    await setSwipeAction(page, "Swipe left", "Delete");

    await page.goto("/mail/inbox");
    await settled(page);

    const row = mailRow(page, INBOX_SUBJECTS.archive);

    await expect(row).toBeVisible();

    const id = ((await row.getAttribute("id")) ?? "").replace("thread_", "");

    const trashed = page.waitForRequest(
        (request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/trash`,
    );

    await fullLeftDrag(page, row);

    await trashed;

    await expect(mailRow(page, INBOX_SUBJECTS.archive), "the row leaves the Inbox").toHaveCount(0);

    const undo = page.getByRole("button", { name: "Undo", exact: true });

    await expect(undo).toBeVisible();
    await undo.click();

    await expect(mailRow(page, INBOX_SUBJECTS.archive), "Undo puts the row back").toBeVisible();
});
