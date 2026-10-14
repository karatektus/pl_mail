import { test, expect, devices, type Locator, type Page } from "./support/test";
import { Finger, recordTouchSamples, touchSamples, velocityOverLast } from "./support/touch";
import { settled } from "./support/motion";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";

/**
 * Row swipe on a phone: right = read/unread, left = archive, fixed defaults.
 *
 * Every figure is derived from the row's own measured width `w` (the li's
 * boundingBox, never a number fitted to output) and the constants in
 * assets/touch_gesture.js and assets/controllers/mail/row_swipe_controller.js:
 *   partial  = 0.35w: release past it opens the row on its action
 *   full     = 0.70w: release past it commits without a tap
 *   FLING    = 0.5 px/ms, with the displacement at least partial
 *   velocity = measured over the last 100ms of samples (VELOCITY_WINDOW_MS)
 * Drags sit at least 0.10w from either threshold: 0.25w (snap back), 0.50w
 * (open, or a fling), 0.85w (commit).
 *
 * A drag that stops for longer than the velocity window before the finger lifts
 * reads as a drop, not a flick: the pause below is 150ms, past the 100ms window.
 *
 * Touch only, below md. Each behaviour has a desktop companion where it matters
 * (the mouse drag test below), and the mouse never starts a swipe.
 */
test.use({ ...devices["Pixel 5"] });

const PARTIAL = 0.35;
const FULL = 0.7;
const FLING_PX_PER_MS = 0.5;
const VELOCITY_WINDOW_MS = 100;
const DROP_PAUSE_MS = 150;

const ROWS = '[data-list-region="rows"]';
const CONTENT = "[data-swipe-content]";

/** A revealed action. Drawn only while its row is open, so visibility is the test for "shown". */
const COMMIT_BUTTON = "[data-swipe-commit]";

/** Where a left drag starts: near the right edge, clear of the avatar on the left. */
const LEFT_START = 0.9;
/** Where a right drag starts: clear of the star on the right, on the avatar's side. */
const RIGHT_START = 0.12;

const archiveUrl = (id: string) => `/status/thread/${id}/archive`;

function threadId(row: Locator): Promise<string> {
    return row.getAttribute("id").then((id) => (id ?? "").replace("thread_", ""));
}

/** The row's width, measured. Every distance in this file is a fraction of it. */
async function rowWidth(row: Locator): Promise<number> {
    const box = await row.boundingBox();

    expect(box, "the row is rendered").not.toBeNull();

    return box!.width;
}

/** A finger on the row, dragged by `fraction` of its width, to the left or the right. */
async function drag(page: Page, row: Locator, direction: "left" | "right", fraction: number, { pause = 0 } = {}): Promise<Finger> {
    const box = await row.boundingBox();

    expect(box, "the row is rendered").not.toBeNull();

    const sign = direction === "left" ? -1 : 1;
    const start = direction === "left" ? LEFT_START : RIGHT_START;
    const finger = await Finger.down(page, box!.x + box!.width * start, box!.y + box!.height / 2);

    await finger.move(sign * fraction * box!.width, 0);

    if (pause > 0) {
        await page.waitForTimeout(pause);
    }

    await finger.up();

    return finger;
}

async function openInbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await settled(page);
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toBeVisible();
}

test.beforeEach(() => {
    seed("seed-mail");
});

// The vertical-drag case fills the Inbox with bulk mail so the list can scroll. The
// pull spec clears it the same way, and for the same reason: the next spec on this
// worker starts from the base fixture.
test.afterAll(() => {
    seed("seed-bulk --clear");
});

test.describe("row swipe on a phone", () => {
    test("a left drag past partial stays open on Archive; tapping it archives the row and offers Undo", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);
        const w = await rowWidth(row);

        // Presence: the revealed action is not in the accessibility tree until the row opens.
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeHidden();

        // The row is the element that carries touch-action, so a horizontal drag is never
        // claimed by the browser. Checked here because the drag below depends on it.
        await expect(row).toHaveCSS("touch-action", "pan-y");

        // 0.5w is between partial (0.35w) and full (0.70w) for any measured w > 0: it opens, and
        // does not commit. The pause keeps the release velocity at zero (see DROP_PAUSE_MS).
        expect(w).toBeGreaterThan(0);
        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });

        await expect(row).toHaveAttribute("data-swipe-side", "left");

        const archive = row.getByRole("button", { name: "Archive", exact: true });

        await expect(archive).toBeVisible();

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await archive.click();
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
        await expect(page.getByRole("button", { name: "Undo", exact: true })).toBeVisible();
    });

    test("a real touch tap on the revealed Archive button archives the row with exactly one POST", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);

        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(row).toHaveAttribute("data-swipe-side", "left");

        const archive = row.getByRole("button", { name: "Archive", exact: true });

        await expect(archive).toBeVisible();

        // Presence: the point at the button's centre is the button, so the touch lands on it.
        const box = await archive.boundingBox();

        expect(box, "the revealed button is rendered").not.toBeNull();

        const x = box!.x + box!.width / 2;
        const y = box!.y + box!.height / 2;
        const hit = await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.closest("[data-swipe-commit]") !== null, { x, y });

        expect(hit, "the touch point is on the revealed button").toBe(true);

        const archivePosts: string[] = [];

        page.on("request", (request) => {
            const path = new URL(request.url()).pathname;

            if (request.method() === "POST" && path.startsWith("/status/")) {
                archivePosts.push(path);
            }
        });

        // A finger, not a mouse: down, a small hold, up. The hold is short of the hold timer's
        // 450ms, so the row's hold gesture never claims it.
        const finger = await Finger.down(page, x, y);

        await page.waitForTimeout(80);
        await finger.up();

        // Presence: the row is gone, and Undo is offered for the archive that happened.
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
        await expect(page.getByRole("button", { name: "Undo", exact: true })).toBeVisible();

        expect(archivePosts, "a touch on the revealed button posts its URL exactly once").toEqual([archiveUrl(id)]);
    });

    test("a touch tap on the open row away from its button closes the row and posts nothing", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);

        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(row).toHaveAttribute("data-swipe-side", "left");

        const archivePosts: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && new URL(request.url()).pathname.startsWith("/status/")) {
                archivePosts.push(new URL(request.url()).pathname);
            }
        });

        // Presence: the row is open and its button is drawn, so the tap below is a tap on
        // the open row, not on a closed one.
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeVisible();

        const box = await row.boundingBox();
        const tap = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        await page.waitForTimeout(80);
        await tap.up();

        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);
        await page.waitForTimeout(300);

        expect(archivePosts, "a tap away from the button posts nothing").toEqual([]);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toBeVisible();
    });

    test("a left drag short of partial snaps back and sends nothing", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const content = row.locator(CONTENT);
        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        const w = await rowWidth(row);
        const box = await row.boundingBox();
        const finger = await Finger.down(page, box!.x + box!.width * LEFT_START, box!.y + box!.height / 2);

        await finger.move(-0.25 * w, 0);

        // Presence, while the finger is still down: the row followed it. Without this, "nothing
        // happened" would pass for a row that never listened.
        await expect.poll(() => content.evaluate((el) => getComputedStyle(el).transform)).not.toBe("none");

        await page.waitForTimeout(DROP_PAUSE_MS);
        await finger.up();

        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(content).toHaveCSS("transform", "none");
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeHidden();

        await page.waitForTimeout(300);
        expect(requests, "a snap back is not a write").toEqual([]);
    });

    test("a left drag past full commits without a tap", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        // The pause keeps the release speed at zero, so only the full threshold can commit this:
        // without it, a release at 0.85w is a fling, and the full threshold is never tested.
        await drag(page, row, "left", 0.85, { pause: DROP_PAUSE_MS });
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("a right drag on an unread row offers Mark as read, then Mark as unread on the next drag", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.read);
        const id = await threadId(row);

        await expect(row).toHaveAttribute("data-unread", "true");

        await drag(page, row, "right", 0.5, { pause: DROP_PAUSE_MS });
        await expect(row).toHaveAttribute("data-swipe-side", "right");

        // An unread row's right action reads "Mark as read", and nothing else.
        await expect(row.getByRole("button", { name: "Mark as read", exact: true })).toBeVisible();
        await expect(row.getByRole("button", { name: "Mark as unread", exact: true })).toBeHidden();

        const read = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/read`);

        await row.getByRole("button", { name: "Mark as read", exact: true }).click();
        await read;

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-unread", "false");

        // The reverse, on the now-read row: "Mark as unread", and the state goes back.
        const again = mailRow(page, INBOX_SUBJECTS.read);

        await drag(page, again, "right", 0.5, { pause: DROP_PAUSE_MS });
        await expect(again.getByRole("button", { name: "Mark as unread", exact: true })).toBeVisible();
        await expect(again.getByRole("button", { name: "Mark as read", exact: true })).toBeHidden();

        const unread = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/read`);

        await again.getByRole("button", { name: "Mark as unread", exact: true }).click();
        await unread;

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-unread", "true");
    });

    test("in Archive both directions are inert, and the same thread restored to the Inbox swipes again", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);

        expect((await ajaxPost(page, archiveUrl(id))).ok(), "the fixture archives the thread").toBe(true);

        await page.goto("/mail/archive");
        await settled(page);

        const archived = mailRow(page, INBOX_SUBJECTS.archive);

        await expect(archived).toBeVisible();

        const content = archived.locator(CONTENT);
        const before = await content.boundingBox();

        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        // A full drag to the left, which would archive an ordinary row. Here nothing may happen:
        // checked first, by name, so a write fails with its URL rather than as a timeout later.
        await drag(page, archived, "left", 0.85, { pause: DROP_PAUSE_MS });
        await page.waitForTimeout(300);

        expect(requests, "a left drag in Archive posted a write").toEqual([]);
        await expect(archived.locator(`[data-swipe-panel="left"] ${COMMIT_BUTTON}`), "a left panel is drawn in Archive").toBeHidden();
        await expect(archived, "the row in Archive left the list").toHaveCount(1);
        await expect(archived, "a left drag in Archive moved the row").not.toHaveAttribute("data-swipe-side", /.+/);

        await drag(page, archived, "right", 0.85, { pause: DROP_PAUSE_MS });
        await page.waitForTimeout(300);

        expect(requests, "a right drag in Archive posted a write").toEqual([]);
        expect(await content.boundingBox(), "neither direction moved the row").toEqual(before);

        // Presence companion: back in the Inbox, the same thread does swipe.
        expect((await ajaxPost(page, `/status/thread/${id}/restore`)).ok()).toBe(true);

        await page.goto("/mail/inbox");
        await settled(page);

        const restored = mailRow(page, INBOX_SUBJECTS.archive);

        await expect(restored).toBeVisible();

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await drag(page, restored, "left", 0.85);
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("a vertical drag on a row scrolls the list and does not open the row", async ({ page }) => {
        // Sixty more messages, so the list has somewhere to scroll to: the presence that the drag was a scroll.
        seed("seed-bulk --clear", "seed-bulk --messages=60");

        await openInbox(page);

        const rows = page.locator(ROWS);
        const row = rows.locator('li[data-controller="mail--message-row"]').first();
        const box = await row.boundingBox();

        const finger = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        // Upwards, so the pull controller (which reads a downward drag from the top) is not involved.
        await finger.move(0, -120);

        // Checked while the finger is still down: a vertical move must never claim the row as a
        // swipe. Checked after the lift, this would pass for a row that claimed and then let go.
        await expect(row, "a vertical drag claimed the row as a swipe").not.toHaveAttribute("data-swipe-side", /.+/);

        await finger.up();
        await page.waitForTimeout(200);

        // Presence: the list scrolled. A drag that did nothing at all would otherwise pass.
        expect(await rows.evaluate((el) => el.scrollTop), "the finger scrolled the list").toBeGreaterThan(0);

        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeHidden();
    });

    test("a fast fling past partial commits; the same distance released slowly only opens", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);
        const w = await rowWidth(row);
        const box = await row.boundingBox();
        const distance = 0.5 * w;

        // Companion first, on the row that stays: the same distance, slowly, opens and does not commit.
        await recordTouchSamples(page);

        const slow = await Finger.down(page, box!.x + box!.width * LEFT_START, box!.y + box!.height / 2);

        for (let i = 0; i < 18; i++) {
            await slow.move(-distance / 18, 0, 1);
            await page.waitForTimeout(50);
        }

        // The recorded samples are what the page measured; the slow release must read below half the fling line.
        const slowSpeed = velocityOverLast(await touchSamples(page), VELOCITY_WINDOW_MS);

        expect(slowSpeed, "the slow drag is under 0.5 x the fling constant").toBeLessThan(0.5 * FLING_PX_PER_MS);

        await slow.up();

        await expect(row).toHaveAttribute("data-swipe-side", "left");
        expect(await mailRow(page, INBOX_SUBJECTS.archive).count(), "a slow drag does not commit").toBe(1);

        // Now the fast one: the same distance, flicked. The velocity is 2x the constant or more.
        await page.reload();
        await settled(page);

        const flicked = mailRow(page, INBOX_SUBJECTS.archive);
        const fastBox = await flicked.boundingBox();

        await recordTouchSamples(page);

        const fast = await Finger.down(page, fastBox!.x + fastBox!.width * LEFT_START, fastBox!.y + fastBox!.height / 2);

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await fast.move(-distance, 0, 6);

        const flickSpeed = velocityOverLast(await touchSamples(page), VELOCITY_WINDOW_MS);

        expect(flickSpeed, "the flick is at least 2x the fling constant").toBeGreaterThanOrEqual(2 * FLING_PX_PER_MS);

        await fast.up();
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

});

/**
 * Desktop width is inert for both pointers, and phone width is not.
 *
 * Each case drags at a desktop window (1280px, so not below 48rem) by a mouse or by a
 * touch, and asserts nothing happened: no swipe state on the row, no transform, no
 * action panel shown, no write posted. Then the window is made phone-width and a TOUCH
 * drag of the same distance, on the same row, acts. The presence half is a touch and not
 * a mouse because a mouse never acts on a phone either: touch is the only pointer that does.
 *
 * hasTouch is on for the whole block, because a CDP finger needs a touch-capable context.
 */
test.describe("row swipe at desktop width is inert, and a phone width is not", () => {
    test.use({ viewport: { width: 1280, height: 800 }, isMobile: false, hasTouch: true });

    /** Writes to /status/ posted from now on, in order. */
    function watchWrites(page: Page): string[] {
        const writes: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                writes.push(request.url());
            }
        });

        return writes;
    }

    /** A mouse drag by `fraction` of the row's width, leftwards. */
    async function mouseDrag(page: Page, row: Locator, fraction: number, { pause = 0 } = {}): Promise<void> {
        const box = await row.boundingBox();

        expect(box, "the row is rendered").not.toBeNull();

        const y = box!.y + box!.height / 2;

        await page.mouse.move(box!.x + box!.width * LEFT_START, y);
        await page.mouse.down();
        await page.mouse.move(box!.x + box!.width * (LEFT_START - fraction), y, { steps: 12 });

        if (pause > 0) {
            await page.waitForTimeout(pause);
        }

        await page.mouse.up();
    }

    /** Nothing happened: asserted per fact, and the write check last, after the settle. */
    async function expectInert(page: Page, row: Locator, writes: string[]): Promise<void> {
        await page.waitForTimeout(300);

        await expect(row, "the row shows a swipe state").not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(row.locator(CONTENT), "the row was moved").toHaveCSS("transform", "none");
        await expect(row.locator(`[data-swipe-panel] ${COMMIT_BUTTON}`).first(), "an action panel is shown").toBeHidden();
        expect(writes, "a write was posted").toEqual([]);
    }

    test("a mouse drag that would open a row does nothing here, and a touch drag at phone width does", async ({ page }) => {
        await openInbox(page);

        const writes = watchWrites(page);

        await mouseDrag(page, mailRow(page, INBOX_SUBJECTS.archive), 0.5, { pause: DROP_PAUSE_MS });
        await expectInert(page, mailRow(page, INBOX_SUBJECTS.archive), writes);

        // Presence: the same row, at phone width, by touch, opens.
        await page.setViewportSize({ width: 393, height: 727 });
        await drag(page, mailRow(page, INBOX_SUBJECTS.archive), "left", 0.5, { pause: DROP_PAUSE_MS });

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-swipe-side", "left");
        await expect(mailRow(page, INBOX_SUBJECTS.archive).locator(`[data-swipe-panel="left"] ${COMMIT_BUTTON}`)).toBeVisible();
        expect(writes, "opening is not a write").toEqual([]);
    });

    test("a mouse drag past full does nothing here, and a touch drag at phone width commits", async ({ page }) => {
        await openInbox(page);

        const writes = watchWrites(page);
        const id = await threadId(mailRow(page, INBOX_SUBJECTS.archive));

        await mouseDrag(page, mailRow(page, INBOX_SUBJECTS.archive), 0.85, { pause: DROP_PAUSE_MS });
        await expectInert(page, mailRow(page, INBOX_SUBJECTS.archive), writes);

        // Presence: at phone width, the same distance by touch commits.
        await page.setViewportSize({ width: 393, height: 727 });

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await drag(page, mailRow(page, INBOX_SUBJECTS.archive), "left", 0.85, { pause: DROP_PAUSE_MS });
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("a touch drag that would open a row does nothing at desktop width, and at phone width it does", async ({ page }) => {
        await openInbox(page);

        const writes = watchWrites(page);

        await drag(page, mailRow(page, INBOX_SUBJECTS.archive), "left", 0.5, { pause: DROP_PAUSE_MS });
        await expectInert(page, mailRow(page, INBOX_SUBJECTS.archive), writes);

        // Presence: the same drag, after the window is made phone-width, opens the row.
        await page.setViewportSize({ width: 393, height: 727 });
        await drag(page, mailRow(page, INBOX_SUBJECTS.archive), "left", 0.5, { pause: DROP_PAUSE_MS });

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-swipe-side", "left");
        expect(writes, "opening is not a write").toEqual([]);
    });

    test("a touch drag past full does nothing at desktop width, and at phone width it commits", async ({ page }) => {
        await openInbox(page);

        const writes = watchWrites(page);
        const id = await threadId(mailRow(page, INBOX_SUBJECTS.archive));

        await drag(page, mailRow(page, INBOX_SUBJECTS.archive), "left", 0.85, { pause: DROP_PAUSE_MS });
        await expectInert(page, mailRow(page, INBOX_SUBJECTS.archive), writes);

        // Presence: the same drag at phone width commits.
        await page.setViewportSize({ width: 393, height: 727 });

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await drag(page, mailRow(page, INBOX_SUBJECTS.archive), "left", 0.85, { pause: DROP_PAUSE_MS });
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });
});

test.describe("row swipe and the list refresh", () => {
    test.beforeEach(async ({ page }) => {
        // The refresh is rate limited (MIN_REFRESH_MS, 15s), and the clock is the only
        // way to get past it without waiting. Installed before the page exists.
        await page.clock.install();
    });

    test("a refresh does not reset an open row, and the server change to another row lands", async ({ page }) => {
        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);
        const other = mailRow(page, INBOX_SUBJECTS.star);
        const openId = await threadId(open);
        const otherId = await threadId(other);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        // A change to the open row (read: the seed leaves it unread, so this really changes it)
        // and to a different row (starred), server side. The read route takes read: true to mark read.
        expect((await ajaxPost(page, `/status/thread/${openId}/read`, { read: true })).ok()).toBe(true);
        expect((await ajaxPost(page, `/status/thread/${otherId}/star`)).ok()).toBe(true);

        const fragment = await refreshAndWaitForFragment(page);

        expect(fragment, "the refresh asked for the list fragment").toBe(true);

        // Landed-proof: the other row now renders as starred.
        await expect(mailRow(page, INBOX_SUBJECTS.star).getByRole("button", { name: "Unstar this message", exact: true })).toBeVisible();

        // And the open row is still open, with its action working, and its own change held back.
        await expect(open).toHaveAttribute("data-swipe-side", "left");
        await expect(open.getByRole("button", { name: "Archive", exact: true })).toBeVisible();
        await expect(open).toHaveAttribute("data-unread", "true");

        // Closing the row: a touch that does not move on the open row closes it.
        const box = await open.boundingBox();
        const tap = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        await tap.up();
        await expect(open).not.toHaveAttribute("data-swipe-side", /.+/);

        // The next refresh applies the held change to the closed row. Its own fragment request is
        // proven too, so this cannot pass on a refresh that never ran.
        expect(await refreshAndWaitForFragment(page), "the second refresh asked for the list fragment").toBe(true);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-unread", "false");
    });

    test("a refresh that removes the open row leaves no stuck transform, and a pull works afterwards", async ({ page }) => {
        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(open);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        // Archived somewhere else (another tab, the bulk actions): the row leaves the Inbox.
        expect((await ajaxPost(page, archiveUrl(id))).ok()).toBe(true);

        await refreshAndWaitForFragment(page);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0, { timeout: 5000 });

        // No orphaned open state: a pull from the top syncs, which it cannot while a row is open.
        const syncs: string[] = [];

        await page.route("**/mail/sync", (route) => {
            if (route.request().method() === "POST") {
                syncs.push(route.request().url());
            }

            return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) });
        });
        await page.route("**/mail/sync/status", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: 0 }) }),
        );

        const rows = await page.locator(ROWS).boundingBox();
        const pull = await Finger.down(page, rows!.x + rows!.width / 2, rows!.y + 20);

        await pull.move(0, 180);
        await pull.up();

        await expect.poll(() => syncs.length).toBe(1);
    });

    test("a pull from the top is refused over an open row, and allowed once the row is closed", async ({ page }) => {
        await page.route("**/mail/sync", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) }),
        );
        await page.route("**/mail/sync/status", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: 0 }) }),
        );

        const syncs: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && new URL(request.url()).pathname === "/mail/sync") {
                syncs.push(request.url());
            }
        });

        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        const box = await open.boundingBox();
        const pulled = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        await pulled.move(0, 180);
        await pulled.up();
        await page.waitForTimeout(400);

        expect(syncs, "an open row is a surface: no pull starts over it").toEqual([]);

        // Presence companion: close the row, and the same pull syncs.
        const closeBox = await open.boundingBox();
        const tap = await Finger.down(page, closeBox!.x + closeBox!.width * 0.5, closeBox!.y + closeBox!.height / 2);

        await tap.up();
        await expect(open).not.toHaveAttribute("data-swipe-side", /.+/);

        const rows = await page.locator(ROWS).boundingBox();
        const pull = await Finger.down(page, rows!.x + rows!.width / 2, rows!.y + 20);

        await pull.move(0, 180);
        await pull.up();

        await expect.poll(() => syncs.length).toBe(1);
    });

    test("a rotation past the phone width closes an open row, and the next refresh lands its change", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);

        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });

        // Presence: the row is open at phone width, so the rotation below is what closes it.
        await expect(row).toHaveAttribute("data-swipe-side", "left");

        // A server change to the open row, held back by the open row until it is closed and refreshed.
        expect((await ajaxPost(page, `/status/thread/${id}/read`, { read: true })).ok()).toBe(true);

        await page.setViewportSize({ width: 1280, height: 800 });

        await expect(row, "a row open at phone width closes when the window passes the breakpoint").not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(row, "and no longer owns its drawn state").not.toHaveAttribute("data-swipe-owned");
        expect(await row.locator(CONTENT).evaluate((el) => el.style.transform), "the content is drawn at rest").toBe("");

        // The next refresh applies the held change to the closed row.
        expect(await refreshAndWaitForFragment(page), "the refresh asked for the list fragment").toBe(true);
        await expect(row).toHaveAttribute("data-unread", "false");
    });

    test("a drag whose row is removed under the finger leaves no claim behind: a pull syncs once, and another row's swipe opens", async ({ page }) => {
        const syncs: string[] = [];

        await page.route("**/mail/sync", (route) => {
            if (route.request().method() === "POST") {
                syncs.push(route.request().url());
            }

            return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) });
        });
        await page.route("**/mail/sync/status", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: 0 }) }),
        );

        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const other = mailRow(page, INBOX_SUBJECTS.star);
        const box = await row.boundingBox();

        expect(box, "the row is rendered").not.toBeNull();

        // A 20px drag: past the 10px direction lock, so the swipe claims the sequence, and short of
        // any reveal. Presence: the row is owned by the drag, which is what makes the claim real.
        const finger = await Finger.down(page, box!.x + box!.width * LEFT_START, box!.y + box!.height / 2);

        await finger.move(-20, 0);
        await expect(row).toHaveAttribute("data-swipe-owned", "");

        // The row leaves the document mid-drag. The touch's events target the detached node, so
        // nothing from here on reaches the swipe's touchend.
        await row.evaluate((el) => el.remove());
        await finger.up();

        // A pull from the top is granted, and syncs exactly once. A stale swipe claim would refuse it.
        const rows = await page.locator(ROWS).boundingBox();
        const pull = await Finger.down(page, rows!.x + rows!.width / 2, rows!.y + 20);

        await pull.move(0, 180);
        await pull.up();

        await page.waitForTimeout(500);
        expect(syncs, "the stale swipe claim does not refuse the pull; exactly one sync").toHaveLength(1);

        // A swipe on another row works after it: presence that the claim was released, not only that the pull ran.
        await drag(page, other, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(other, "another row's swipe opens after the stale drag").toHaveAttribute("data-swipe-side", "left");
    });

    test("a second row's drag closes the open one, and the drag itself does nothing", async ({ page }) => {
        await openInbox(page);

        const first = mailRow(page, INBOX_SUBJECTS.archive);
        const second = mailRow(page, INBOX_SUBJECTS.star);
        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        await drag(page, first, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(first).toHaveAttribute("data-swipe-side", "left");

        await drag(page, second, "left", 0.5);

        await expect(first).not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(second).not.toHaveAttribute("data-swipe-side", /.+/);
        await page.waitForTimeout(300);
        expect(requests).toEqual([]);
    });

    test("a row's checkbox and the toolbar's Archive reach the same selection, so the touch-free path exists", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.star);
        const checkbox = row.getByRole("checkbox", { name: /Select/ });

        await expect(checkbox).toHaveCount(1);

        // The avatar is the checkbox's label: pressing it selects the row, as a tap does.
        await row.locator("label").first().click();
        await expect(checkbox).toBeChecked();

        // The toolbar's own Archive is the other equivalent: it files the selection, as the swipe files the row.
        const archive = page.locator("[data-mail--list-toolbar-target='actions']").getByRole("button", { name: "Archive", exact: true }).first();

        await expect(archive).toBeVisible();
        await archive.click();

        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);
    });
});

test.describe("row swipe with reduced motion", () => {
    test.use({ reducedMotion: "reduce" });

    test.beforeEach(async ({ page }) => {
        await page.clock.install();
    });

    test("the open and the commit behave the same, and the row has no running animation", async ({ page }) => {
        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        // Presence precondition: the row is open. Then nothing on it is animating.
        const running = await open.evaluate((el) => el.getAnimations({ subtree: true }).length);

        expect(running, "no animation runs on the open row under reduced motion").toBe(0);

        // A touch elsewhere closes the open row, and is consumed: the commit below needs the row closed first.
        const closeBox = await open.boundingBox();
        const tap = await Finger.down(page, closeBox!.x + closeBox!.width * 0.5, closeBox!.y + closeBox!.height / 2);

        await tap.up();
        await expect(open).not.toHaveAttribute("data-swipe-side", /.+/);

        // The commit, on a different row, is the same as with motion.
        const committed = mailRow(page, INBOX_SUBJECTS.star);
        const id = await threadId(committed);
        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/archive`);

        await drag(page, committed, "left", 0.85);
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);
    });

    test("closing an open row runs no animation under reduced motion, and the same close animates with motion allowed", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const content = row.locator(CONTENT);

        // A close is a tap on the open row, away from its button. The row is closed when the tap lifts.
        const closeTap = async () => {
            const box = await row.boundingBox();
            const tap = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

            await page.waitForTimeout(80);
            await tap.up();
        };

        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(row).toHaveAttribute("data-swipe-side", "left");
        await closeTap();
        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);

        // Invariant 6: the close is a jump. The stylesheet eases the content's transform, so
        // any running animation here is the close the reduced-motion user was promised away from.
        expect(await content.evaluate((el) => el.getAnimations().length), "no animation runs on a close under reduced motion").toBe(0);

        // Presence: the same close, with motion allowed, does animate, so the zero above is the
        // reduced-motion reset and not an element that never moves.
        await page.emulateMedia({ reducedMotion: "no-preference" });
        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(row).toHaveAttribute("data-swipe-side", "left");
        await closeTap();
        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);

        expect(await content.evaluate((el) => el.getAnimations().length), "with motion allowed the close animates").toBeGreaterThan(0);
    });
});

/**
 * Force one list refresh and wait for the fragment request that carries it.
 *
 * The refresh is the mailbox-synced event the body dispatches on a Mercure
 * update, with the clock moved past MIN_REFRESH_MS first. Returns whether the
 * request was seen, so a test can prove the refresh really happened rather than
 * passing because nothing moved.
 */
async function refreshAndWaitForFragment(page: Page): Promise<boolean> {
    await page.clock.fastForward(16_000);

    const seen = page.waitForRequest((request) => request.headers()["x-list-fragment"] !== undefined, { timeout: 5000 }).then(
        () => true,
        () => false,
    );

    await page.evaluate(() => {
        document.body.dispatchEvent(
            new CustomEvent("core--mercure:mailbox-synced", {
                bubbles: true,
                detail: { poll: true },
            }),
        );
    });

    return seen;
}
