import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * The calendar in both of its shapes.
 *
 * The pane cases are the ones worth having. A docked pane that shares its row
 * with the mail panes can go wrong in ways a page cannot — it can fail to give
 * width back, it can forget its size on reload, and a dialog opened from inside
 * it can be clipped by the pane's own backdrop-filter. All three have precedent
 * in this codebase.
 *
 * Seeds and removes its own events: the suite is not idempotent, and an event
 * left behind changes what the next run's assertions count.
 */

const TITLE = `E2E event ${Date.now()}`;

test.describe("calendar", () => {
    test.afterEach(async ({ page }) => {
        // Delete anything this spec created, so a second run starts where the
        // first did. A recurring event puts one chip on every day of the week,
        // and deleting the series clears all of them, so this loops on "is any
        // still there" rather than on a count.
        //
        // Never waits for networkidle: the app holds a Mercure EventSource
        // open for the whole session, so the network is never idle and the
        // wait only ever ends in a timeout.
        for (let attempt = 0; attempt < 3; attempt++) {
            await page.goto("/calendar/week");

            const chips = page.getByRole("button", { name: new RegExp(TITLE) });

            if ((await chips.count()) === 0) {
                return;
            }

            await chips.first().click();
            await page.getByRole("button", { name: "Delete" }).click();
            await expect(page.getByRole("button", { name: new RegExp(TITLE) })).toHaveCount(0);
        }
    });

    test("creates an event and shows it in the week", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        await modal.getByLabel("Title").fill(TITLE);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByRole("button", { name: new RegExp(TITLE) }).first()).toBeVisible();
    });

    test("switches between day, week and month", async ({ page }) => {
        await page.goto("/calendar/week");

        for (const view of ["Day", "Month", "Week"]) {
            await page.getByRole("link", { name: view, exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`/calendar/${view.toLowerCase()}`));
        }
    });

    test("a repeating event appears on more than one day", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        await modal.getByLabel("Title").fill(TITLE);
        await modal.getByLabel("Repeat").selectOption("daily");
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page).toHaveURL(/\/calendar\/week/);

        // A daily event inside a seven-day window is on every remaining day of
        // it, so more than one chip is the whole assertion.
        const chips = page.getByRole("button", { name: new RegExp(TITLE) });
        await expect(chips.first()).toBeVisible();
        expect(await chips.count()).toBeGreaterThan(1);
    });
});

test.describe("calendar pane", () => {
    /**
     * Open/closed is a stored user preference, so it survives from whichever
     * test ran last. Start every case from closed rather than assuming.
     */
    async function ensurePaneClosed(page: Page) {
        await page.goto("/mail/inbox");

        const wrapper = page.locator('[data-ui--split-target="wrapper"]');

        if (await wrapper.isVisible()) {
            await page.locator("[data-calendar-toggle]").click();
            await expect(wrapper).toBeHidden();
        }
    }

    test("docks beside the mail and gives the width back when closed", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);

        const mainPane = page.locator(".main-pane").first();
        const widthWithoutPane = (await mainPane.boundingBox())!.width;

        await page.locator("[data-calendar-toggle]").click();

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        const widthWithPane = (await mainPane.boundingBox())!.width;
        expect(widthWithPane).toBeLessThan(widthWithoutPane);

        // The mail is still there beside it — the pane took width, not the page.
        await expect(page.locator("#message-list")).toBeVisible();

        await page.locator("[data-calendar-toggle]").click();
        await expect(paneFrame).toBeHidden();
        expect((await mainPane.boundingBox())!.width).toBeCloseTo(widthWithoutPane, 0);
    });

    test("remembers its width across a reload", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await page.locator("[data-calendar-toggle]").click();

        const pane = page.locator('[data-ui--split-target="pane"]');
        await expect(pane).toBeVisible();

        const handle = page.locator('[data-ui--split-target="handle"]');

        // Width is a stored preference, so a previous run can leave the pane
        // already at its ceiling with no room to widen. Double-click resets it
        // to the default first, which makes this test start from a known place
        // rather than from wherever the last one finished.
        await handle.dblclick();
        await expect(pane).toHaveCSS("width", "380px");

        const before = (await pane.boundingBox())!;

        // Drag left, which widens a right-hand pane.
        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 120, box.y + box.height / 2, { steps: 10 });

        // The write happens on release and is deliberately not awaited by the
        // UI, so wait for it here rather than racing the reload against it.
        const persisted = page.waitForResponse(
            async (response) =>
                response.url().includes("/calendar/pane-state") &&
                "POST" === response.request().method() &&
                (await response.json()).width > Math.round(before.width),
        );

        await page.mouse.up();
        await persisted;

        const dragged = (await pane.boundingBox())!.width;
        expect(dragged).toBeGreaterThan(before.width);

        // The width is rendered inline on the next paint, so the reload is the
        // assertion, not the drag.
        await page.reload();
        await expect(pane).toBeVisible();
        expect((await pane.boundingBox())!.width).toBeCloseTo(dragged, 0);
    });

    test("the event dialog is not clipped by the pane", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await page.locator("[data-calendar-toggle]").click();

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        await paneFrame.getByLabel("New event").click();

        // backdrop-filter on an ancestor makes it a containing block for fixed
        // positioning, so a dialog rendered inside the pane would be inset to
        // it instead of covering the viewport. Width is the tell.
        const backdrop = page.locator("#modal-backdrop");
        await expect(backdrop).toBeVisible();

        const paneWidth = (await page.locator('[data-ui--split-target="pane"]').boundingBox())!.width;
        expect((await backdrop.boundingBox())!.width).toBeGreaterThan(paneWidth * 2);
    });

    test("reaches the month view from inside the pane", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await page.locator("[data-calendar-toggle]").click();

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        // Icons, not words, at this width — but all four views are reachable,
        // and switching one stays inside the frame rather than navigating the
        // whole page away from the mail.
        await paneFrame.getByRole("link", { name: "Month" }).click();

        await expect(paneFrame.getByRole("link", { name: "Month" })).toHaveAttribute(
            "aria-current",
            "page",
        );
        await expect(page.locator("#message-list")).toBeVisible();
    });

    test("will not squeeze the mail pane below its minimum", async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await ensurePaneClosed(page);
        await page.locator("[data-calendar-toggle]").click();

        const mainPane = page.locator('[data-ui--split-target="main"]');
        const handle = page.locator('[data-ui--split-target="handle"]');
        await expect(handle).toBeVisible();

        // Drag far past anywhere sensible: the clamp is what is under test, not
        // the arithmetic of a modest drag.
        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(20, box.y + box.height / 2, { steps: 12 });
        await page.mouse.up();

        expect((await mainPane.boundingBox())!.width).toBeGreaterThanOrEqual(419);
    });

    test("centres its grip in the gap between the panes", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await page.locator("[data-calendar-toggle]").click();

        const mainPane = page.locator('[data-ui--split-target="main"]');
        const pane = page.locator('[data-ui--split-target="pane"]');
        const grip = page.locator('[data-ui--split-target="handle"] span');

        const mainBox = (await mainPane.boundingBox())!;
        const paneBox = (await pane.boundingBox())!;
        const gripBox = (await grip.boundingBox())!;

        // The row's own gap falls to the left of the wrapper, so a handle only
        // one gutter wide sits off to the calendar's side of the gap.
        const gapCentre = (mainBox.x + mainBox.width + paneBox.x) / 2;
        expect(gripBox.x + gripBox.width / 2).toBeCloseTo(gapCentre, 0);
    });

    /**
     * The rows below the list respond to the LIST's width, not the window's.
     * Before the container query they switched on the viewport, so a wide
     * window with a wide calendar pane left a list narrower than a phone still
     * rendering one-line rows, truncated to nothing.
     */
    test("stacks the mail rows once the pane has taken enough width", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);

        const inlineDate = page.locator('[data-row-date="inline"]').first();
        const stackedDate = page.locator('[data-row-date="stacked"]').first();

        // On display rather than visibility: the inline date column is laid out
        // as a zero-height positioning context for an absolutely placed span,
        // so Playwright calls it hidden either way. Which layout the container
        // query picked is the actual subject here.
        await expect(inlineDate).toHaveCSS("display", "flex");
        await expect(stackedDate).toHaveCSS("display", "none");

        await page.locator("[data-calendar-toggle]").click();
        await expect(page.locator("turbo-frame#calendar-pane-frame")).toBeVisible();

        // Drag the pane out to its limit, which is where the list is tightest.
        const handle = page.locator('[data-ui--split-target="handle"]');
        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(20, box.y + box.height / 2, { steps: 12 });
        await page.mouse.up();

        await expect(stackedDate).toHaveCSS("display", "block");
        await expect(inlineDate).toHaveCSS("display", "none");
    });

    /**
     * The same control at every width. On a phone there is no room to dock
     * beside the mail, so the pane takes the row instead — but it is still the
     * pane, on the same page, and closing it puts the mail back. Navigating to
     * a separate calendar page could not do that.
     */
    test("takes over the row on a phone instead of navigating away", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await ensurePaneClosed(page);

        const mailPane = page.locator(".main-pane").first();
        await expect(mailPane).toBeVisible();

        await page.locator("[data-calendar-toggle]").click();

        await expect(page.locator("turbo-frame#calendar-pane-frame")).toBeVisible();
        await expect(mailPane).toBeHidden();

        // Still /mail/inbox — nothing navigated.
        await expect(page).toHaveURL(/\/mail\/inbox/);

        await page.locator("[data-calendar-toggle]").click();
        await expect(mailPane).toBeVisible();
    });

    /** The drawer keeps a plain link to the full page, for a real destination. */
    test("the drawer still links to the calendar page", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await ensurePaneClosed(page);

        await page.getByRole("button", { name: /menu|sidebar/i }).first().click();
        await page.locator("#sidebar-drawer-inner").getByRole("link", { name: "Calendar" }).click();

        await expect(page).toHaveURL(/\/calendar$/);
        await expect(page.locator("#message-list")).toHaveCount(0);
    });
});
