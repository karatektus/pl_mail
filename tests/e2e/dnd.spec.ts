import type { Locator, Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * Dragging a conversation onto a folder, and onto a category tab.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * WHY THIS HAS TO BE A BROWSER TEST
 * ─────────────────────────────────
 * Every other part of the feature is pinned where it is cheaper to pin:
 * ThreadMoveTest has what a move does to the labels, BulkMoveGuardTest has what
 * the route refuses. Neither can see the part that actually breaks. This
 * feature is four templates and one controller agreeing about attributes that
 * nothing validates — a row that forgets `draggable`, a sidebar row that spells
 * `data-dnd-folder` slightly differently, a drop target that is the wrong
 * element — and every one of those fails silently, with no console error and
 * nothing to grep for. It is the same shape as the bug that made the AI panel
 * say nothing for months: each file is correct on its own.
 *
 * WHY THE POINTER IS DRIVEN BY HAND
 * ─────────────────────────────────
 * Dispatching a DragEvent with a hand-built DataTransfer would exercise the
 * handlers and prove nothing about the gesture — it would pass just as well
 * against a row that is not `draggable` at all, which is one of the failures
 * most worth catching. locator.dragTo() drives a real pointer and would be the
 * obvious tool, and it cannot serve either: it runs its actionability check on
 * the TARGET before the pointer has moved, and half the targets here do not
 * exist until a drag is already in flight. So the mouse is driven step by step
 * below, which is Playwright's own documented recipe for a manual drag.
 */

/** The conversation rows, which are the drag sources. */
const ROWS = "#message-list li[data-dnd-thread]";

/**
 * The folder dropped onto.
 *
 * A custom label rather than a system row, deliberately: it is the case with
 * the most moving parts, and the only one that resolves its destination through
 * the label's binding rather than through a role.
 */
const FOLDER = "E2E Label";

test.beforeAll(() => {
    seed("seed-label");
});

/**
 * Put one conversation in another category, so the tab strip exists.
 *
 * The strip only renders when more than one category holds mail, and the mail
 * fixture is four threads that are all Primary — so without this there is no
 * strip, no ghost tabs, and nothing to drop onto. See the note on it in
 * mail/inbox.html.twig, which explains why an inbox in that state cannot be
 * dragged into at all.
 *
 * Done through the application's own endpoint rather than a console seed,
 * because there is no console seed that sets a category and adding one would be
 * a fixture that exists for one test. It is mildly circular — the route being
 * used as scaffolding is the route the drop posts to — and the circularity is
 * bounded: what this file is for is the GESTURE, and the endpoint itself is
 * pinned without a browser in ThreadMoveTest and BulkMoveGuardTest. A broken
 * endpoint fails those first.
 */
async function fileInAnotherCategory(page: Page, row: Locator): Promise<void> {
    const id = Number((await row.getAttribute("id"))?.replace("thread_", ""));
    const token = await page.locator('meta[name="csrf-token"]').getAttribute("content");

    const response = await page.request.post("/status/bulk/category", {
        headers: { "Content-Type": "application/json", "X-CSRF-Token": token ?? "" },
        data: { ids: [id], category: "promotions" },
    });

    expect(response.ok()).toBe(true);
}

/**
 * Pick a row up and let it go over a target.
 *
 * Three details here are each a failure that was actually hit:
 *
 * `hover()` on the source, never a boundingBox() read. The list animates in —
 * rows slide from the left, staggered — so a box measured on arrival is where
 * the row WAS partway through its travel. Written the other way first, and it
 * grabbed whichever row happened to be under that stale coordinate: the drag
 * worked perfectly and moved a different conversation.
 *
 * A move to somewhere neutral before the target. Chromium starts a native drag
 * once the pointer has travelled with the button down, so a single jump onto
 * the target is one event — a drop on the far side of a drag that never began.
 * It is also what makes the ghost category tabs exist to be measured.
 *
 * A short nudge after arriving. A multi-step move dispatches one drag event per
 * step and the last one lags: with the pointer already over Forums, the most
 * recent event the page had seen was still the one over Social, so the
 * highlight — and a drop — belonged to the tab next door. Two more pixels
 * settle it.
 */
async function dragOnto(page: Page, row: Locator, target: Locator): Promise<void> {
    await row.hover();
    await page.mouse.down();

    const from = await row.boundingBox();
    await page.mouse.move((from?.x ?? 0) / 2, from?.y ?? 0, { steps: 12 });

    // Measured after the drag has begun, so a target that only exists during
    // one can be aimed at. Not target.hover(): its actionability check asks
    // whether the element receives events, and what receives them during a
    // native drag is the drag image — so the check never passes and the step
    // hangs until the test times out, with no call log to say why.
    const to = await target.boundingBox();

    if (null === to) {
        throw new Error("the drop target is not on screen");
    }

    await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, { steps: 12 });
    await page.mouse.move(to.x + to.width / 2 + 2, to.y + to.height / 2, { steps: 2 });

    // The affordance, asserted where it is the only thing on screen saying the
    // drop will land: a strip of dashed rows says "these will accept it" and
    // this says "this one". Checked before the button comes up, because it is
    // gone the instant the drag ends.
    await expect(target).toHaveAttribute("data-dnd-over", "true");

    await page.mouse.up();
}

/**
 * The subject, to recognise a row by wherever it ends up.
 *
 * From the overlay anchor's aria-label rather than from the row's text. A row
 * reads out as far more than its subject — the sender, the date, the snippet
 * and a run of screen-reader-only labels — so slicing words out of innerText
 * produced a search string of "NOT SHOWN TO YOU", which matched nothing and
 * failed as though the move had.
 */
async function subjectOf(row: Locator): Promise<string> {
    return (await row.locator("a[aria-label]").first().getAttribute("aria-label")) ?? "";
}

test.describe("dragging a conversation", () => {
    test("onto a folder in the sidebar files it there", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        // A row the mail fixture recreates on every run, so this spec can be
        // run twice: it MOVES a conversation out of the inbox, and one picked
        // by position would be gone the second time.
        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toBeVisible();

        const subject = await subjectOf(row);

        const folder = page
            .locator("#sidebar [data-dnd-folder]")
            .filter({ hasText: FOLDER })
            .first();
        await expect(folder).toBeVisible();

        await dragOnto(page, row, folder);

        // The row leaves the list it was dragged from. Waited for rather than
        // asserted immediately: the drop posts, the answer removes the row, and
        // the list frame is re-read afterwards — so "gone" has to survive the
        // refresh, not just the stream.
        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(0);

        // And it says where it went. This is the whole of the feedback for a
        // gesture that ends with the row gone; a move that worked and said
        // nothing is indistinguishable from a drag that missed.
        await expect(page.getByText(`Moved to ${FOLDER}`)).toBeVisible();

        // The other half, and the one a stream alone could fake: it is actually
        // in the folder now.
        await folder.locator("a").first().click();
        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(1);
    });

    test("onto a category tab re-files it, including one with no mail yet", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        await fileInAnotherCategory(page, mailRow(page, INBOX_SUBJECTS.archive));

        await page.goto("/mail/inbox");
        await settled(page);

        const row = mailRow(page, INBOX_SUBJECTS.star);
        await expect(row).toBeVisible();

        const subject = await subjectOf(row);

        // Forums is the category nothing ever lands in on its own, which is
        // exactly why it needs a ghost: the strip only shows categories that
        // hold mail, so without one it could never acquire any.
        const ghost = page.locator('[data-dnd-ghost][data-dnd-category="forums"]');

        // In the document and not on screen, which is the arrangement under
        // test — a target fetched a request after the drag has begun is a
        // target the pointer has already travelled past.
        await expect(ghost).toHaveCount(1);
        await expect(ghost).toBeHidden();

        await dragOnto(page, row, ghost);

        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(0);
        await expect(page.getByText("Moved to Forums")).toBeVisible();

        // Now that something is in it, Forums is a real tab rather than a ghost
        // — and it holds the conversation.
        await page.goto("/mail/inbox?tab=forums");
        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(1);
    });
});
