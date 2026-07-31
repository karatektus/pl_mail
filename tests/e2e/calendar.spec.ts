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

        await page.getByRole("button", { name: "New event" }).click();

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

        await page.getByRole("button", { name: "New event" }).click();

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
            await page.getByRole("link", { name: "Calendar" }).click();
            await expect(wrapper).toBeHidden();
        }
    }

    test("docks beside the mail and gives the width back when closed", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);

        const mainPane = page.locator(".main-pane").first();
        const widthWithoutPane = (await mainPane.boundingBox())!.width;

        await page.getByRole("link", { name: "Calendar" }).click();

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        const widthWithPane = (await mainPane.boundingBox())!.width;
        expect(widthWithPane).toBeLessThan(widthWithoutPane);

        // The mail is still there beside it — the pane took width, not the page.
        await expect(page.locator("#message-list")).toBeVisible();

        await page.getByRole("link", { name: "Calendar" }).click();
        await expect(paneFrame).toBeHidden();
        expect((await mainPane.boundingBox())!.width).toBeCloseTo(widthWithoutPane, 0);
    });

    test("remembers its width across a reload", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await page.getByRole("link", { name: "Calendar" }).click();

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
        await page.mouse.up();

        const dragged = (await pane.boundingBox())!.width;
        expect(dragged).toBeGreaterThan(before.width);

        // The width is written on release, and rendered inline on the next
        // paint — so the reload is the assertion, not the drag.
        await page.reload();
        await expect(pane).toBeVisible();
        expect((await pane.boundingBox())!.width).toBeCloseTo(dragged, 0);
    });

    test("the event dialog is not clipped by the pane", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await page.getByRole("link", { name: "Calendar" }).click();

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

    test("is absent on a phone, where the link opens the page instead", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto("/mail/inbox");

        await expect(page.locator('[data-ui--split-target="pane"]')).toBeHidden();

        await page.getByRole("button", { name: /menu|sidebar/i }).first().click();
        await page.getByRole("link", { name: "Calendar" }).click();

        await expect(page).toHaveURL(/\/calendar$/);
        await expect(page.locator("#message-list")).toHaveCount(0);
    });
});
