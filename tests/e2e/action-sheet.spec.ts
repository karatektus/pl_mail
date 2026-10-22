import { test, expect, devices, type Locator, type Page } from "./support/test";
import { Finger } from "./support/touch";
import { settled } from "./support/motion";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { openSurfaceOf, pullIsGranted } from "./support/arbiter";

/**
 * The long-press action sheet on a phone (E3 in the touch-gesture plan).
 *
 * Timing runs on Playwright's fake clock, installed before navigation and then
 * paused, so the hold is decided exactly when the test says. Every figure is
 * derived from the constants in assets/touch_gesture.js, and is written next to
 * the test that uses it:
 *   HOLD_MS = 450        the hold is decided when the clock reaches touchstart + 450
 *   HOLD_SLOP_PX = 8     movement of up to 8 keeps the hold; more than 8 cancels it
 *   DIRECTION_LOCK_PX = 10   the reach at which a sequence is decided vertical or horizontal
 *
 * The page's own clock is frozen while a test holds a finger down, so "runFor(400)"
 * means exactly 400ms of the hold, whatever the machine is doing.
 *
 * Desktop companion: the same press with a mouse, and a touch at desktop width,
 * opens nothing. The phone companion runs in the same test as the desktop one.
 */
test.use({ ...devices["Pixel 5"] });

const SHEET = '[data-controller~="mail--action-sheet"]';
const DIALOG = "role=dialog";
const SHELL = '[data-controller~="ui--sidebar-drawer"]';

/** The list's rows scroller, as the list frame draws it. */
const ROWS_SELECTOR = '[data-list-region="rows"]';

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    // The clock is controlled before the page exists, so its first timers are the test's too.
    await page.clock.install();
});

/** Opens the Inbox with the fake clock paused, so no timer moves unless a test moves it. */
async function openInbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await settled(page);
    await page.clock.pauseAt(new Date(Date.now() + 5000));
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toBeVisible();
}

/**
 * A finger down at the middle of the row, away from the avatar and the star.
 *
 * Waits for the row to be marked pressed before returning. That mark is set in the same
 * touchstart handler that starts the hold timer, so once it is there the timer exists
 * and the fake clock can be moved. Without the wait, runFor() can run before the page
 * has handled the touchstart, and the timer is then set a whole hold later.
 */
async function pressRow(page: Page, row: Locator): Promise<Finger> {
    const box = await row.boundingBox();

    expect(box, "the row is rendered").not.toBeNull();

    const finger = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

    await expect(row).toHaveAttribute("data-pressed", "");

    return finger;
}

function sheet(page: Page): Locator {
    return page.locator(SHEET);
}

function dialog(page: Page): Locator {
    return page.getByRole("dialog", { name: "Actions for this conversation" });
}

test.describe("long press opens the action sheet on a phone", () => {
    test("a hold of 400ms opens nothing, and a hold of 500ms opens the sheet", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        // touchstart at t0. The hold is decided at t0 + 450. At t0 + 400 it is not yet,
        // so nothing is open: the margin on the far side is 50ms, the 450 boundary itself.
        await page.clock.runFor(400);
        await expect(sheet(page)).toHaveCount(0);

        // At t0 + 500 the hold has been decided at t0 + 450, so the sheet is open.
        await page.clock.runFor(100);
        await expect(sheet(page)).toHaveCount(1);
        await expect(dialog(page)).toBeVisible();

        // Presence companion for the reduced-motion test: without it, 0s would pass for any sheet.
        await expect(dialog(page)).toHaveCSS("animation-duration", "0.2s");

        await finger.up();

        // Presence companion: the press that opened the sheet is still the same row.
        await expect(row).toBeVisible();
    });

    test("a 20px drag at 200ms cancels the hold and reads as a scroll; the same press without the drag opens the sheet", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);

        // 20px down at t0 + 200: reach 20 > 8 cancels the hold, and 20 >= 10 decides the sequence
        // vertical (a scroll or pull), so the hold never comes at t0 + 450. This test does not prove
        // the slop boundary itself: a move of 8 to 10px is never delivered here, because Chromium
        // does not send a touchmove inside its own touch slop. The boundary (9px at 200ms cancels,
        // 9px at 500ms is past the window) is proved at the arbiter, in touch-gesture-arbiter.spec.ts.
        const moved = await pressRow(page, row);

        await page.clock.runFor(200);
        await moved.move(0, 20, 1);
        await page.clock.runFor(400);
        await expect(sheet(page)).toHaveCount(0);
        await moved.up();

        // Presence companion: the same press, with no move, opens the sheet.
        const still = await pressRow(page, row);

        await page.clock.runFor(500);
        await expect(sheet(page)).toHaveCount(1);
        await still.up();
    });

    test("while a finger is on the row it is marked pressed, and the marks go when it lifts", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const overlay = row.locator(":scope > a[tabindex='-1']");
        const finger = await pressRow(page, row);

        await expect(row).toHaveAttribute("data-pressed", "");
        await expect(overlay).toHaveAttribute("data-pressed", "");

        // The iOS hooks: no text selection and no touch callout, on the row and on its overlay.
        await expect(row).toHaveCSS("user-select", "none");
        await expect(overlay).toHaveCSS("user-select", "none");

        await page.clock.runFor(500);
        await finger.up();

        await expect(row).not.toHaveAttribute("data-pressed", "");
        await expect(overlay).not.toHaveAttribute("data-pressed", "");
    });

    test("the sheet offers the Inbox's entries, and not the ones only the bin offers", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        const panel = dialog(page);

        await expect(panel.getByRole("button", { name: "Select", exact: true })).toBeVisible();
        await expect(panel.getByRole("button", { name: /^Mark as (read|unread)$/ })).toBeVisible();
        await expect(panel.getByRole("button", { name: "Archive", exact: true })).toBeVisible();
        await expect(panel.getByRole("button", { name: /Star this message|Unstar this message/ })).toBeVisible();
        await expect(panel.getByRole("button", { name: "Move to", exact: true })).toBeVisible();
        await expect(panel.getByRole("button", { name: "Label as", exact: true })).toBeVisible();
        await expect(panel.getByRole("button", { name: "Snooze", exact: true })).toBeVisible();
        await expect(panel.getByRole("button", { name: "Mark as spam", exact: true })).toBeVisible();
        await expect(panel.getByRole("button", { name: "Delete", exact: true })).toBeVisible();

        // Absence, with the presence companion above in the same test: the bin's entries are not offered here.
        await expect(panel.getByRole("button", { name: "Move to inbox", exact: true })).toBeHidden();
        await expect(panel.getByRole("button", { name: "Delete forever", exact: true })).toBeHidden();
    });

    test("Select ticks the row with a real change, so the toolbar counts it", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        await dialog(page).getByRole("button", { name: "Select", exact: true }).click();

        await expect(sheet(page)).toHaveCount(0);
        await expect(row.locator("[data-thread-select]")).toBeChecked();

        // The toolbar's count reads the change event, not the checkbox state: "1" is the presence.
        await expect(page.locator('[data-mail--list-toolbar-target="selectionCount"]')).toHaveText("1");
    });

    test("Escape and the backdrop each close it, and focus goes back to the row", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);

        let finger = await pressRow(page, row);
        await page.clock.runFor(500);
        await finger.up();

        await expect(dialog(page)).toBeVisible();
        await page.keyboard.press("Escape");
        await expect(sheet(page)).toHaveCount(0);
        await expect(row.locator("[data-thread-select]")).toBeFocused();

        finger = await pressRow(page, row);
        await page.clock.runFor(500);
        await finger.up();

        await expect(dialog(page)).toBeVisible();

        // The click that follows the opening finger's lift is ignored for CLICK_WINDOW_MS (300ms), so a
        // real tap on the backdrop comes after that window: 400ms of the fake clock is past it.
        await page.clock.runFor(400);

        // The backdrop is the page above the panel, so a point near the top of the screen is on it.
        await sheet(page).locator(".absolute.inset-0").first().click({ position: { x: 5, y: 5 } });
        await expect(sheet(page)).toHaveCount(0);
    });

    test("while open the page behind is inert, the sheet is a dialog, and Tab stays off the page", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        await expect(dialog(page)).toHaveAttribute("aria-modal", "true");
        await expect(page.locator(SHELL)).toHaveJSProperty("inert", true);

        for (let i = 0; i < 14; i++) {
            await page.keyboard.press("Tab");
        }

        // Focus is in the sheet, or has left the page to the browser (body). Never on the page behind.
        const onBackground = await page.evaluate(() => {
            const active = document.activeElement;

            return active !== null && active !== document.body && active.closest('[data-controller~="mail--action-sheet"]') === null;
        });

        expect(onBackground).toBe(false);

        await page.keyboard.press("Escape");

        // Presence companion: once closed, the page is live again.
        await expect(page.locator(SHELL)).toHaveJSProperty("inert", false);
    });

    test("Move to opens the picker for this row, and posts this row's id", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = (await row.getAttribute("id"))?.replace("thread_", "") ?? "";
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        await dialog(page).getByRole("button", { name: "Move to", exact: true }).click();

        const panel = sheet(page).locator('[data-mail--move-menu-target="panel"]');

        await expect(panel).toBeVisible();

        const posted = page.waitForRequest((request) => request.method() === "POST" && request.url().includes("/status/bulk/move-to"));

        await panel.getByRole("menuitem").first().click();

        const body = JSON.parse((await posted).postData() ?? "{}") as { ids: number[] };

        // Presence: exactly this row. A selection-wide move would post the toolbar's ids instead.
        expect(body.ids).toEqual([Number(id)]);
    });

    test("Snooze posts this row's snooze route with the wake time", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = (await row.getAttribute("id"))?.replace("thread_", "") ?? "";
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        await dialog(page).getByRole("button", { name: "Snooze", exact: true }).click();

        const option = sheet(page).locator("[data-snooze-key]:not([hidden])").first();

        await expect(option).toBeVisible();

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/snooze`);

        await option.click();

        const body = JSON.parse((await posted).postData() ?? "{}") as { until?: string | null };

        expect(typeof body.until).toBe("string");
    });

    test("reduced motion: the sheet's slide takes no time", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        // The slide is the panel's own animation, of --sheet-duration, which is 0ms under reduced motion.
        await expect(dialog(page)).toHaveCSS("animation-duration", "0s");
    });
});

/** The thread id a row carries, from its DOM id `thread_<id>`. */
async function threadIdOf(row: Locator): Promise<string> {
    return ((await row.getAttribute("id")) ?? "").replace("thread_", "");
}

/**
 * Forces a list refresh, the way a Mercure sync does, and waits out the refresh floor.
 *
 * The refresh is held MIN_REFRESH_MS (15s) from the last one, on the fake clock, so the
 * clock is moved past it. Callers wait for the X-List-Fragment request, which is the proof
 * the refresh ran.
 */
async function refreshList(page: Page): Promise<void> {
    await page.evaluate(() => {
        document.body.dispatchEvent(new CustomEvent("core--mercure:mailbox-synced", { bubbles: true, detail: { poll: true } }));
    });

    await page.clock.runFor(16000);
}

test.describe("a background refresh does not disturb the sheet", () => {
    test("a refresh that changes another row leaves the sheet open, and its action posts the URL it opened with", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const other = mailRow(page, INBOX_SUBJECTS.read);
        const id = await threadIdOf(row);
        const otherId = await threadIdOf(other);

        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();
        await expect(sheet(page)).toHaveCount(1);

        // A change to a different row, made on the server and not through the sheet.
        expect((await ajaxPost(page, `/status/thread/${otherId}/star`)).ok()).toBe(true);

        const fragment = page.waitForRequest((request) => request.headers()["x-list-fragment"] !== undefined);

        await refreshList(page);
        await fragment;

        // The change is on screen: the refresh really landed, so "still open" means something.
        await expect(other).toHaveAttribute("data-starred", "true");
        await expect(sheet(page)).toHaveCount(1);

        // The sheet's action still posts to the held row's own URL.
        const posted = page.waitForRequest(
            (request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/star`,
        );

        await dialog(page).getByRole("button", { name: /Star this message|Unstar this message/ }).click();
        await posted;
    });

    test("a refresh that removes the held row closes the sheet", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadIdOf(row);

        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();
        await expect(sheet(page)).toHaveCount(1);

        expect((await ajaxPost(page, `/status/thread/${id}/archive`)).ok()).toBe(true);

        const fragment = page.waitForRequest((request) => request.headers()["x-list-fragment"] !== undefined);

        await refreshList(page);
        await fragment;

        // The refresh took the row out, and the sheet with it. The other row is still there.
        await expect(row).toHaveCount(0);
        await expect(sheet(page)).toHaveCount(0);
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
    });
});

test.describe("a row that leaves under a held finger", () => {
    test("a row removed before its hold fires opens no sheet and throws nothing, and the next hold opens one", async ({ page }) => {
        const errors: string[] = [];

        page.on("pageerror", (error) => errors.push(error.message));
        page.on("console", (message) => {
            if (message.type() === "error") {
                errors.push(message.text());
            }
        });

        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        // The row goes while the finger is down and before the 450ms hold is decided. The touch
        // targets the removed node, so nothing from here on reaches the hold's touchend.
        await row.evaluate((element) => element.remove());
        await expect(row).toHaveCount(0);

        await page.clock.runFor(500);
        await finger.up();

        await expect(sheet(page)).toHaveCount(0);
        expect(errors, "a removed row opens no sheet and throws nothing").toEqual([]);

        // The removed row claimed nothing: a pull is granted before any other touch lands. (The next
        // touch would release a stale claim anyway, so this is checked first.)
        expect(await pullIsGranted(page), "a removed row's hold claims nothing").toBe(true);

        // Presence companion: a hold on a row that is still there opens the sheet, so the
        // zero above is the removed row and not a hold that never fires.
        const other = mailRow(page, INBOX_SUBJECTS.read);
        const again = await pressRow(page, other);

        await page.clock.runFor(500);
        await again.up();

        await expect(sheet(page)).toHaveCount(1);
        expect(errors, "the next hold throws nothing either").toEqual([]);

        await page.keyboard.press("Escape");
        await expect(sheet(page)).toHaveCount(0);

        // And a pull from the top is granted afterwards: the stale hold holds no claim.
        expect(await pullIsGranted(page)).toBe(true);
    });

    test("a held row removed under the finger leaves no hold claim behind: a later touch releases it, and a pull is granted", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);

        // Presence: the hold opened the sheet, which claims the surface while the finger is down.
        await expect(dialog(page)).toBeVisible();
        expect(await openSurfaceOf(page)).toBe("sheet");

        // The row goes under the finger. The sheet closes with it, and the finger's touchend never
        // reaches the hold, so the hold's claim is still held when the finger lifts.
        await row.evaluate((element) => element.remove());
        await expect(sheet(page)).toHaveCount(0);
        await finger.up();

        // A new touch on the list begins a new sequence, which abandons the stale hold's claim.
        const rows = await page.locator(ROWS_SELECTOR).boundingBox();
        const next = await Finger.down(page, rows!.x + rows!.width / 2, rows!.y + 20);

        expect(await pullIsGranted(page), "the stale hold's claim was released by the new touch").toBe(true);

        await next.up();
    });
});

/** The DOM id of a seeded label's entry in the label menus, found by its name. */
async function labelIdNamed(page: Page, name: string): Promise<string> {
    const id = await page.locator("[data-label-id]").filter({ hasText: name }).first().getAttribute("data-label-id");

    expect(id, `the label "${name}" is listed`).not.toBeNull();

    return id!;
}

test.describe("the label picker in the sheet", () => {
    // seed-mail (the top-level beforeEach) wipes the account's threads; the label is user-scoped and
    // seed-label puts it on the account again, so the row can carry it.
    test.beforeEach(() => {
        seed("seed-label");
    });

    test("Label as ticks exactly the labels this row carries", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadIdOf(row);
        const label = await labelIdNamed(page, "E2E Label");

        // The row gets the label on the server. A reload draws the row with it in data-label-ids.
        expect((await ajaxPost(page, `/status/thread/${id}/label`, { labelId: Number(label), attach: true })).ok()).toBe(true);

        await openInbox(page);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-label-ids", label);

        const finger = await pressRow(page, mailRow(page, INBOX_SUBJECTS.archive));

        await page.clock.runFor(500);
        await finger.up();

        await dialog(page).getByRole("button", { name: "Label as", exact: true }).click();

        const panel = sheet(page).locator('[data-mail--label-menu-target="panel"]');

        await expect(panel).toBeVisible();

        // Presence: the user's labels are listed. The ticks are counted and compared by id, so a sheet
        // that ticks nothing, or the wrong label, fails by name.
        await expect(panel.locator("[data-label-id]")).not.toHaveCount(0);

        const ticked = await panel.locator('[data-attached="true"]').evaluateAll((els) => els.map((el) => el.getAttribute("data-label-id")));

        expect(ticked, "the sheet ticks exactly the labels the row carries").toEqual([label]);
    });

    test("ticking one label keeps the sheet open, and the picker ticks a second one", async ({ page }) => {
        await openInbox(page);

        // A second label, made through the sidebar's Create label, as a person does. The name is
        // unique per run, so a repeat run does not collide with the label an earlier run made.
        const created = `E2E Second ${Date.now()}`;

        // The modal's form loads on timers, and the fake clock is paused for this file, so the clock
        // runs for the creation only. openInbox() pauses it again.
        await page.clock.resume();

        // On a phone the sidebar is a drawer: open it with the burger, then close it again.
        await page.locator('[data-ui--sidebar-drawer-target="burger"]').click();
        await page.locator("#sidebar-drawer").getByRole("button", { name: "Create label" }).click();

        const modal = page.locator("#modal-backdrop");

        await expect(modal).toBeVisible();
        await expect(modal.locator("#modal").getByLabel("Name")).toBeVisible();
        await modal.locator("#modal").getByLabel("Name").fill(created);
        await modal.getByRole("button", { name: "Save" }).click();
        await expect(modal).toBeHidden();
        await page.keyboard.press("Escape");

        await openInbox(page);

        const first = await labelIdNamed(page, "E2E Label");
        const second = await labelIdNamed(page, created);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadIdOf(row);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        await dialog(page).getByRole("button", { name: "Label as", exact: true }).click();

        const panel = sheet(page).locator('[data-mail--label-menu-target="panel"]');

        await expect(panel).toBeVisible();

        // Presence: the row node the sheet was opened for, which the tick's stream replaces.
        await page.evaluate((domId) => {
            (window as unknown as { __openedRow: Element | null }).__openedRow = document.getElementById(domId);
        }, `thread_${id}`);

        const firstTick = page.waitForResponse((response) => response.url().includes(`/status/thread/${id}/label`));

        await panel.locator(`[data-label-id="${first}"]`).click();
        await firstTick;

        // The stream is applied on the page's timers and frames, which the paused fake clock does not
        // run. Move it on in steps until the row carries the tick, so the stream lands the way it does
        // on a phone, and the wait ends on the row, not on a fixed time.
        await expect
            .poll(async () => {
                await page.clock.runFor(50);

                return mailRow(page, INBOX_SUBJECTS.archive).getAttribute("data-label-ids");
            }, { timeout: 5_000 })
            .toBe(first);

        // Presence: the stream has landed on the row (data-label-ids is only ever set by it), and the
        // stream replaced the node the sheet was opened for, so that node is no longer in the document.
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-label-ids", first);
        expect(
            await page.evaluate(() => (window as unknown as { __openedRow: Element | null }).__openedRow?.isConnected ?? null),
            "the tick replaced the opened row",
        ).toBe(false);

        // Ticking one label leaves the sheet open, though its row was redrawn: the row is still there.
        await expect(sheet(page), "ticking one label leaves the sheet open").toHaveCount(1);
        await expect(panel.locator(`[data-label-id="${first}"]`)).toHaveAttribute("data-attached", "true");

        // The picker can tick a second label in the same open sheet.
        const secondTick = page.waitForResponse((response) => response.url().includes(`/status/thread/${id}/label`));

        await panel.locator(`[data-label-id="${second}"]`).click();
        await secondTick;

        await expect(sheet(page)).toHaveCount(1);
        await expect(panel.locator(`[data-label-id="${second}"]`)).toHaveAttribute("data-attached", "true");
    });
});

test.describe("rows are not native drags on a phone", () => {
    test("a thread row is draggable=false on a phone, and a refresh does not restore it", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);

        // The server renders draggable="true" for a thread that is not a draft. The phone turns it off.
        await expect(row).toHaveAttribute("draggable", "false");

        // A refresh morphs the row back to the server's markup. The list puts it back, and the test
        // only passes if the refresh really ran: the X-List-Fragment request is the proof.
        const fragment = page.waitForRequest((request) => request.headers()["x-list-fragment"] !== undefined);

        await refreshList(page);
        await fragment;

        await expect(row).toHaveAttribute("draggable", "false");
    });
});

test.describe("desktop width: the same press opens nothing", () => {
    test.use({ viewport: { width: 1280, height: 800 }, hasTouch: false, isMobile: false });

    test("a thread row stays draggable with a mouse, so the drag still works where it is used", async ({ page }) => {
        await openInbox(page);

        // Companion for the phone test: the same row, the same server markup, not switched off here.
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("draggable", "true");
    });

    test("a mouse long press and a touch at desktop width both open nothing", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const box = await row.boundingBox();

        expect(box).not.toBeNull();

        await page.mouse.move(box!.x + box!.width * 0.5, box!.y + box!.height / 2);
        await page.mouse.down();
        await page.clock.runFor(600);
        await page.mouse.up();

        await expect(sheet(page)).toHaveCount(0);

        // Presence companion at this width: the row is the same row, and the page is live.
        await expect(row).toBeVisible();
        await expect(page.locator(SHELL)).toHaveJSProperty("inert", false);
    });
});

test.describe("the open sheet is a surface the arbiter honours", () => {
    test("while open the arbiter names the sheet and refuses a pull; after close it names nothing and grants one", async ({ page }) => {
        await openInbox(page);

        // Presence: nothing is open before the sheet is.
        expect(await openSurfaceOf(page)).toBeNull();

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();
        await expect(dialog(page)).toBeVisible();

        expect(await openSurfaceOf(page)).toBe("sheet");
        expect(await pullIsGranted(page)).toBe(false);

        await page.keyboard.press("Escape");
        await expect(sheet(page)).toHaveCount(0);

        // Presence companion: after close, the same probes are granted and unnamed.
        expect(await openSurfaceOf(page)).toBeNull();
        expect(await pullIsGranted(page)).toBe(true);
    });
});

test.describe("the sheet clears the home indicator", () => {
    /**
     * Chromium cannot set env(safe-area-inset-bottom), which is 0 here, so the value cannot be
     * changed by the test. What can be asserted is that the rule the phone applies carries the
     * inset: the stylesheet's own rule for the panel names env(safe-area-inset-bottom), and the
     * computed padding is at least the base (1rem, the size the template had as pb-4). The
     * real clearance of the home indicator on an iPhone is a manual check.
     */
    test("the panel's bottom padding is the base plus the safe-area inset, in the stylesheet", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();
        await expect(dialog(page)).toBeVisible();

        // Presence: the computed base padding is at least 1rem (16px).
        const padding = await dialog(page).evaluate((element) => parseFloat(getComputedStyle(element).paddingBottom));

        expect(padding).toBeGreaterThanOrEqual(16);

        // The rule itself: find the panel's padding-bottom in every stylesheet, through the CSSOM.
        const declared = await page.evaluate(() => {
            const found: string[] = [];
            const visit = (rules: CSSRuleList) => {
                for (const rule of Array.from(rules)) {
                    if (rule instanceof CSSStyleRule && rule.selectorText.split(",").some((s) => s.trim() === ".action-sheet-panel")) {
                        found.push(rule.style.paddingBottom);
                    }

                    if ("cssRules" in rule) {
                        visit((rule as CSSGroupingRule).cssRules);
                    }
                }
            };

            for (const sheet of Array.from(document.styleSheets)) {
                visit(sheet.cssRules);
            }

            return found;
        });

        expect(declared.join(" | ")).toContain("env(safe-area-inset-bottom");
    });
});

test.describe("Create label from the sheet", () => {
    test("choosing Create label opens the label modal, and the sheet is gone", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const finger = await pressRow(page, row);

        await page.clock.runFor(500);
        await finger.up();

        await dialog(page).getByRole("button", { name: "Label as", exact: true }).click();

        const panel = sheet(page).locator('[data-mail--label-menu-target="panel"]');

        await expect(panel).toBeVisible();

        // The label menu's "Create label" opens the shared modal, which is outside the sheet.
        await panel.locator('button[data-controller~="ui--modal"]').click();

        // The sheet closes a tick after the click (the click is still on its way), so the fake clock moves past it.
        await page.clock.runFor(50);

        // Presence: the modal is open. Without it, "sheet gone" could be a sheet that broke.
        await expect(page.locator("#modal-backdrop")).toBeVisible();
        await expect(sheet(page)).toHaveCount(0);
    });
});
