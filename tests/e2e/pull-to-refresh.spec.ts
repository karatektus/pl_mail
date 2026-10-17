import { test, expect, devices, type Page } from "./support/test";
import { Finger, recordTouchMoves, touchMoveCancelable } from "./support/touch";
import { settled } from "./support/motion";
import { seed } from "./support/config";

/**
 * Pull to refresh on a phone, driven by real touches.
 *
 * Derivations (k is the resistance, owner default 0.5):
 *   displayed distance = finger dy from touchstart x k
 *   release point      = displayed 70px, so finger travel 70 / 0.5 = 140px
 *   PULL_PAST  = 180px finger -> displayed 90 >= 70: release, one sync
 *   PULL_SHORT = 120px finger -> displayed 60 <  70: no release, no sync
 * A test that moves the constants must re-derive these two numbers.
 *
 * The sync endpoints are answered by the spec, not by the server. The question is
 * whether the gesture asks for a sync, and how many, not what the sync does; a
 * real run would change the test account's mail. The answers keep the topbar
 * button running until a test says otherwise, so the running guard can be tested.
 */
test.use({ ...devices["Pixel 5"] });

// The base seed has four inbox threads, which cannot overflow a phone's list. Sixty
// messages overflow it, so the scroll case has something to scroll. --messages adds
// to the bulk mailbox rather than replacing it, so each test starts from a clear one
// and the last test leaves none behind for the next spec on this worker.
test.beforeEach(() => {
    seed("seed-bulk --clear", "seed-bulk --messages=60");
});

test.afterAll(() => {
    seed("seed-bulk --clear");
});

const PULL_PAST = 180;
const PULL_SHORT = 120;

const ROWS = '[data-list-region="rows"]';
const INDICATOR = '[data-mail--pull-to-refresh-target="indicator"]';
const ICON = '[data-mail--pull-to-refresh-target="icon"]';
const BURGER = '[data-ui--sidebar-drawer-target="burger"]';

const isSync = (url: URL) => url.pathname === "/mail/sync";
const isStatus = (url: URL) => url.pathname === "/mail/sync/status";

/**
 * Answer the sync endpoints, and count the POSTs that reach them.
 *
 * `pending` is what the status endpoint reports: 0 lets the button finish, 1
 * keeps it running for the length of the test.
 */
async function mockSync(page: Page, { pending = 0 } = {}): Promise<{ posts: number; pending: number }> {
    // `pending` is read on every status answer, so a test can end a run it started by
    // setting it to 0 while the run is open.
    const counter = { posts: 0, pending };

    await page.route(isSync, async (route) => {
        if (route.request().method() === "POST") {
            counter.posts++;
        }

        await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) });
    });
    await page.route(isStatus, (route) =>
        route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: counter.pending }) }),
    );

    return counter;
}

/** The list's rows scroller, the surface a pull starts on. */
async function rowsStart(page: Page): Promise<{ x: number; y: number }> {
    const box = await page.locator(ROWS).boundingBox();

    expect(box, "the rows scroller is rendered").not.toBeNull();

    return { x: box!.x + box!.width / 2, y: box!.y + 20 };
}

async function openInbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await settled(page);
}

test.describe("pull to refresh on a phone", () => {
    test("a pull past the release point syncs once, and reads release before the finger lifts", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);

        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "release");

        await finger.up();

        await expect.poll(() => sync.posts).toBe(1);
        await page.waitForTimeout(300);
        expect(sync.posts, "exactly one sync for one pull").toBe(1);
    });

    test("a pull short of the release point tracks the finger but does not sync", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_SHORT);

        // Presence companion: the indicator followed the finger. A test that only
        // checked for no POST would pass for a gesture that never started.
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "pulling");

        await finger.up();
        await page.waitForTimeout(500);

        expect(sync.posts).toBe(0);
    });

    test("a pull from a list that is scrolled down scrolls the list and does not sync", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const rows = page.locator(ROWS);

        // Presence precondition: the list has to be able to scroll to 300, or the
        // assertion below would read 0 for a list that never moved.
        await rows.evaluate((el) => {
            el.scrollTop = 300;
        });
        expect(await rows.evaluate((el) => el.scrollTop)).toBe(300);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();
        await page.waitForTimeout(300);

        expect(sync.posts).toBe(0);
        expect(await rows.evaluate((el) => el.scrollTop), "the finger scrolled the list").toBeLessThan(300);
    });

    test("a pull while the drawer is open does not sync, and the same pull does once it is closed", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        await page.locator(BURGER).click();
        await expect(page.locator("#sidebar-drawer")).not.toHaveAttribute("inert", "");

        // Where the drawer is open, the backdrop is what a touch lands on, so the
        // list never sees the touch at all. That is what the browser does, and it
        // is recorded here: the open-surface check in the gesture module is covered
        // by its node-side spec, not by this one.
        //
        // "Where the drawer is open" means beside the drawer, not under it. The
        // drawer is w-64 (256px) of Pixel 5's 393, and the middle of the rows is
        // x≈196 — inside the drawer once it has arrived. This used to touch that
        // middle straight after `inert` came off, which is the START of a 200ms
        // slide, so the hit-test found the backdrop only while the drawer was
        // still mostly off-screen. A slower runner reached it later, found the
        // drawer, and the test failed with "drawer" (on CI from 2026-10-16, on
        // every branch). So: wait until the drawer has stopped moving, then
        // touch the strip of backdrop to its right, which is the drawer-open
        // touch that can actually reach the list's part of the screen.
        const drawer = page.locator("#sidebar-drawer");

        await expect.poll(() => drawer.evaluate((el) => el.getBoundingClientRect().left)).toBe(0);

        const drawerBox = (await drawer.boundingBox())!;
        const rowsBox = (await page.locator(ROWS).boundingBox())!;
        const besideDrawer = drawerBox.x + drawerBox.width;
        const start = {
            x: (besideDrawer + rowsBox.x + rowsBox.width) / 2,
            y: (await rowsStart(page)).y,
        };

        expect(start.x, "the rows reach past the open drawer").toBeGreaterThan(besideDrawer);

        const hit = await page.evaluate(({ x, y }) => {
            const element = document.elementFromPoint(x, y);

            return element?.closest("[data-ui--sidebar-drawer-target]")?.getAttribute("data-ui--sidebar-drawer-target") ?? null;
        }, start);

        expect(hit, "the open drawer's backdrop takes the touch").toBe("backdrop");

        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();
        await page.waitForTimeout(300);

        expect(sync.posts).toBe(0);

        // Presence companion: close the drawer, and the same pull syncs.
        await page.keyboard.press("Escape");
        await expect(page.locator("#sidebar-drawer")).toHaveAttribute("inert", "");

        // The same point as the first pull, so the only thing that changed
        // between the two is the drawer.
        const second = await Finger.down(page, start.x, start.y);

        await second.move(0, PULL_PAST);
        await second.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("a horizontal drag at the top does not sync, and a vertical pull after it does", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        // The drag runs 140px down as well (the release travel, 70 / 0.5), so a pull
        // that ignored the arbiter would release and sync. The arbiter's answer is
        // horizontal, because 180 > 140. Only that decision stops it.
        const sideways = await Finger.down(page, start.x, start.y);

        await sideways.move(PULL_PAST, 140);
        await sideways.up();
        await page.waitForTimeout(300);

        expect(sync.posts).toBe(0);

        // Presence companion, in the same test: the list still takes a vertical pull.
        const down = await Finger.down(page, start.x, start.y);

        await down.move(0, PULL_PAST);
        await down.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("every touchmove during a pull is cancelable, and the pull still syncs once", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);
        await recordTouchMoves(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        // Two small moves (4px in all), then the rest. Chromium delivers no touchmove
        // for movement inside its touch slop, so those two make no event; the events
        // recorded are the 8 moves of the rest, each one past the lock. The count is
        // the number of moves made after the slop, derived from the steps: 8. The
        // assertion is on delivered events, not on the 10 moves made.
        await finger.move(0, 4, 2);
        await finger.move(0, PULL_PAST - 4, 8);
        await finger.up();

        await expect.poll(() => sync.posts).toBe(1);

        const cancelable = await touchMoveCancelable(page);

        expect(cancelable).toHaveLength(8);
        expect(cancelable.every((value) => value === true), `cancelable per move: ${cancelable}`).toBe(true);
    });

    test("while a sync runs after a release the indicator rests in view and spins, and slides away when it stops", async ({ page }) => {
        const sync = await mockSync(page, { pending: 1 });

        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();

        const indicator = page.locator(INDICATOR);
        const list = page.locator("#message-list");

        await expect.poll(() => sync.posts).toBe(1);
        await expect(indicator).toHaveAttribute("data-state", "syncing");

        // Derivation: the pill is 25px tall in the layout (6px padding each side around a 13px line),
        // and the rest offset is 48px, so its top is 48 - 25 = 23px below the list's top.
        // A pill that rests where the hidden one sits would have its bottom at the list's
        // top, and fail the first check.
        // Wait for the indicator's own transition to finish: the slide from the release point
        // to the rest point is 200ms, and a box read mid-slide proves nothing about where it
        // rests. Only the indicator's own animations count: the spinning icon is a child, and
        // the spin runs for as long as the sync does, so the page as a whole never settles.
        await expect
            .poll(() => indicator.evaluate((el) => el.getAnimations().length), { timeout: 2_000 })
            .toBe(0);

        const listBox = await list.boundingBox();
        const box = await indicator.boundingBox();
        const viewport = page.viewportSize();

        expect(listBox).not.toBeNull();
        expect(box).not.toBeNull();
        expect(box!.y, "the indicator rests below the top of the list").toBeGreaterThanOrEqual(listBox!.y);
        expect(box!.y + box!.height, "the indicator is in the viewport").toBeLessThanOrEqual(viewport!.height);
        await expect(page.locator(ICON)).toHaveClass(/fa-spin/);

        // End the run. The stopped event takes the indicator back out of sight, above the
        // list's top, once its slide has finished.
        sync.pending = 0;
        await expect(indicator).toHaveAttribute("data-state", "idle", { timeout: 15_000 });
        await expect
            .poll(async () => {
                const hidden = await indicator.boundingBox();
                const top = await list.boundingBox();

                return hidden!.y + hidden!.height <= top!.y + 1;
            }, { timeout: 5_000 })
            .toBe(true);
    });

    test("a touch cancelled mid-pull aborts the pull: no sync, and the indicator returns to idle", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        const cancelled = await Finger.down(page, start.x, start.y);

        await cancelled.move(0, PULL_PAST);
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "release");

        await cancelled.cancel();

        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "idle");
        await page.waitForTimeout(500);

        expect(sync.posts, "a cancelled touch syncs nothing").toBe(0);

        // Presence companion, in the same test: a pull that ends with touchend syncs.
        const ended = await Finger.down(page, start.x, start.y);

        await ended.move(0, PULL_PAST);
        await ended.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("a second finger during a pull aborts it: both fingers up syncs nothing", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "release");

        // The second finger lands 100px to the right of the first, which is still on screen.
        await finger.addFinger(start.x + 100, start.y + PULL_PAST);

        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "idle");

        await finger.up();
        await page.waitForTimeout(500);

        expect(sync.posts, "a two-finger touch syncs nothing").toBe(0);

        // Presence companion: a later single-finger pull still syncs.
        const later = await Finger.down(page, start.x, start.y);

        await later.move(0, PULL_PAST);
        await later.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("a pull whose rows are replaced under the finger leaves no claim behind: a later touch syncs nothing, and the next pull syncs once", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        // Record the element the first finger lands on, so the test can show it is the
        // one that gets detached. A touch whose target stays in the document would
        // still reach the controller, and the test would pass without the fault.
        await page.evaluate(() => {
            document.addEventListener(
                "touchstart",
                (event) => {
                    (window as unknown as { __origin: Element | null }).__origin = event.target as Element;
                },
                { capture: true, passive: true },
            );
        });

        const start = await rowsStart(page);
        const stale = await Finger.down(page, start.x, start.y);

        await stale.move(0, PULL_PAST);
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "release");

        // Presence: the touch landed on a row inside the rows region, not on the region itself.
        // Replacing the region's children detaches that row, and the region stays in place.
        const detached = await page.evaluate(() => {
            const origin = (window as unknown as { __origin: Element | null }).__origin;
            const rows = document.querySelector('[data-list-region="rows"]');

            if (origin === null || rows === null || origin === rows || !rows.contains(origin)) {
                return "the touch did not land on a row";
            }

            rows.innerHTML = rows.innerHTML;

            return origin.isConnected ? "the touch target is still attached" : "detached";
        });

        expect(detached).toBe("detached");

        await stale.up();

        // A tap that does not move: no pull, no sync, on any branch. It has to be a tap
        // with no touchmove, because a touchmove during a stale pull resets the displayed
        // distance to the finger's travel (0 here) and would hide the fault. The tap's
        // synthesised click is swallowed, so the row does not open and the list stays put.
        await page.evaluate(() => {
            window.addEventListener(
                "click",
                (event) => {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                },
                { capture: true },
            );
        });

        const fresh = await Finger.down(page, start.x, start.y);

        await fresh.up();
        await page.waitForTimeout(500);

        expect(sync.posts, "a touch after a lost pull syncs nothing").toBe(0);

        // Presence companion: a fresh pull from the top still syncs, exactly once.
        const pull = await Finger.down(page, start.x, start.y);

        await pull.move(0, PULL_PAST);
        await pull.up();

        await expect.poll(() => sync.posts).toBe(1);
        await page.waitForTimeout(300);
        expect(sync.posts, "exactly one sync for the fresh pull").toBe(1);
    });

    test("a second pull while the sync is still running does not sync again", async ({ page }) => {
        const sync = await mockSync(page, { pending: 1 });

        await openInbox(page);

        const start = await rowsStart(page);
        const first = await Finger.down(page, start.x, start.y);

        await first.move(0, PULL_PAST);
        await first.up();

        // Presence companion: the first pull started the sync.
        await expect.poll(() => sync.posts).toBe(1);
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "syncing");

        const second = await Finger.down(page, start.x, start.y);

        await second.move(0, PULL_PAST);
        await second.up();
        await page.waitForTimeout(500);

        expect(sync.posts, "the running guard holds the second pull").toBe(1);
    });

    test("a touch drag on a desktop-width window does nothing, and the same drag syncs on a phone width", async ({ page }) => {
        const sync = await mockSync(page);

        await page.setViewportSize({ width: 1280, height: 800 });
        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();
        await page.waitForTimeout(300);

        expect(sync.posts, "no pull at md and up").toBe(0);

        // Presence companion: the same touch, narrowed to a phone width, syncs.
        await page.setViewportSize({ width: 393, height: 727 });
        await openInbox(page);

        const phone = await rowsStart(page);
        const again = await Finger.down(page, phone.x, phone.y);

        await again.move(0, PULL_PAST);
        await again.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("a mouse drag does nothing on a desktop", async ({ page }) => {
        const sync = await mockSync(page);

        await page.setViewportSize({ width: 1280, height: 800 });
        await openInbox(page);

        const start = await rowsStart(page);

        // Presence companion: count the mouse moves the page receives, so "no sync"
        // means the drag was delivered and ignored, not never sent.
        await page.evaluate(() => {
            const win = window as unknown as { __mouseMoves: number };

            win.__mouseMoves = 0;
            window.addEventListener("mousemove", () => win.__mouseMoves++);
        });

        await page.mouse.move(start.x, start.y);
        await page.mouse.down();
        await page.mouse.move(start.x, start.y + PULL_PAST, { steps: 6 });
        await page.mouse.up();
        await page.waitForTimeout(300);

        expect(await page.evaluate(() => (window as unknown as { __mouseMoves: number }).__mouseMoves)).toBeGreaterThan(0);
        expect(sync.posts).toBe(0);
    });

    test("with reduced motion the indicator jumps back, and without it the indicator slides", async ({ page }) => {
        await mockSync(page);

        await page.emulateMedia({ reducedMotion: "no-preference" });
        await openInbox(page);

        const start = await rowsStart(page);
        const first = await Finger.down(page, start.x, start.y);

        await first.move(0, PULL_PAST);
        await first.up();

        // Presence companion: the slide takes time when motion is allowed.
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "idle");
        const slide = await page.locator(INDICATOR).evaluate((el) => getComputedStyle(el).transitionDuration);

        expect(slide).not.toBe("0s");

        await page.emulateMedia({ reducedMotion: "reduce" });
        await openInbox(page);

        const again = await rowsStart(page);
        const second = await Finger.down(page, again.x, again.y);

        await second.move(0, PULL_PAST);
        await second.up();

        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "idle");
        expect(await page.locator(INDICATOR).evaluate((el) => getComputedStyle(el).transitionDuration)).toBe("0s");
    });
});
