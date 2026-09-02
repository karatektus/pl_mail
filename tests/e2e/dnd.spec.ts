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
 * The custom label dropped onto, which ATTACHES rather than moves.
 *
 * The two halves of the sidebar mean different things: the rows above LABELS
 * are places and a drop there files the conversation, the LABELS section is
 * tags and a drop there adds one and leaves the mail where it was. Both are
 * exercised below, because the pair is the claim — either alone passes for a
 * build in which a drop does only one thing.
 */
const LABEL = "E2E Label";

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
    test("onto a label adds it and leaves the conversation where it was", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        // A row the mail fixture recreates on every run, so this spec can be
        // run twice: these tests move conversations about, and one picked by
        // position would be somewhere else the second time.
        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toBeVisible();

        const subject = await subjectOf(row);

        const label = page
            .locator("#sidebar [data-dnd-label]")
            .filter({ hasText: LABEL })
            .first();
        await expect(label).toBeVisible();

        await dragOnto(page, row, label);

        await expect(page.getByText(`Labelled ${LABEL}`)).toBeVisible();

        // STAYS. This is the whole difference from a move, and it is asserted
        // after the frame refresh rather than straight after the stream — the
        // row being redrawn and the row surviving a re-read are two different
        // claims, and only the second one means the mail is still in the inbox.
        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(1);

        // And it really has the label, not just a toast saying so.
        await label.locator("a").first().click();
        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(1);
    });

    /**
     * Dropped on Inbox from the archive, because Inbox is the one system row
     * this fixture is guaranteed to have.
     *
     * System labels are created lazily — the first archive, the first snooze,
     * the first folder sync — so a freshly seeded user has no Trash or Archive
     * LABEL and those rows carry no drop target at all. Inbox always exists.
     *
     * It cannot be dropped on from the inbox, though: there it is the open
     * folder and refuses drops by design, since moving a conversation into the
     * list it is already in is a no-op that would make the row vanish and come
     * straight back. So the conversation is archived first and dragged home,
     * which is a real move in a direction somebody actually makes.
     */
    test("onto a folder above the labels moves it out of the list", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        const staged = mailRow(page, INBOX_SUBJECTS.archive);
        await expect(staged).toBeVisible();

        const subject = await subjectOf(staged);
        const id      = Number((await staged.getAttribute("id"))?.replace("thread_", ""));
        const token   = await page.locator('meta[name="csrf-token"]').getAttribute("content");

        const archived = await page.request.post("/status/bulk/archive", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token ?? "" },
            data: { ids: [id] },
        });
        expect(archived.ok()).toBe(true);

        await page.goto("/mail/archive");
        await settled(page);

        const row = page.locator(ROWS).filter({ hasText: subject });
        await expect(row).toHaveCount(1);

        const inbox = page
            .locator("#sidebar [data-dnd-folder]")
            .filter({ hasText: "Inbox" })
            .first();
        await expect(inbox).toBeVisible();

        await dragOnto(page, row.first(), inbox);

        // GONE from the archive, which is what makes this a move rather than a
        // label. Waited for rather than asserted immediately: the drop posts,
        // the answer removes the row, and the list frame is re-read afterwards
        // — so "gone" has to survive the refresh, not just the stream.
        await expect(page.locator(ROWS).filter({ hasText: subject })).toHaveCount(0);
        await expect(page.getByText(/^Moved to /)).toBeVisible();
    });

    /**
     * What the pointer carries, which is the one part of a drag no screenshot
     * can show — the drag image is drawn by the compositor, outside the page,
     * so it is absent from every capture Playwright takes.
     *
     * So the assertion is on what the page HANDS the browser. It is worth
     * making: a single row used to drag a snapshot of itself, a thousand pixels
     * of mail list travelling under the pointer to a sidebar a third that wide,
     * and the target spent the whole gesture behind it. Nothing visible would
     * have caught that going back.
     */
    test("carries a pill naming the conversation, not a picture of the whole row", async ({ page }) => {
        // Before the first navigation, so the hook is in place when dragstart
        // fires. It records rather than replaces: the real setDragImage still
        // runs, so the drag behaves exactly as it does for a person.
        await page.addInitScript(() => {
            const original = DataTransfer.prototype.setDragImage;

            (window as unknown as { __dragImages: string[] }).__dragImages = [];

            DataTransfer.prototype.setDragImage = function (image, x, y) {
                (window as unknown as { __dragImages: string[] }).__dragImages.push(
                    `${(image as HTMLElement).className}|${image.textContent ?? ""}`,
                );

                return original.call(this, image, x, y);
            };
        });

        await page.goto("/mail/inbox");
        await settled(page);

        // A row of its own. Every test in this file MOVES the conversation it
        // drags, and the mail fixture is seeded once per worker rather than
        // once per test — so two tests naming the same row means the second one
        // looking for something the first filed away. Read is the folder test's,
        // Star and Archive are the category test's, and this one takes Trash.
        const row = mailRow(page, INBOX_SUBJECTS.trash);
        await expect(row).toBeVisible();

        const subject = await subjectOf(row);

        const label = page
            .locator("#sidebar [data-dnd-label]")
            .filter({ hasText: LABEL })
            .first();

        await dragOnto(page, row, label);

        const handed = await page.evaluate(
            () => (window as unknown as { __dragImages: string[] }).__dragImages,
        );

        expect(handed).toEqual([`dnd-drag-image|${subject}`]);
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
