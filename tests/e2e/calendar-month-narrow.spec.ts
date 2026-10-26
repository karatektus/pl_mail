import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * The calendar on a phone, and a calendar left open (#253).
 *
 * Below 39rem a month's days are bars, and the view lists one day in full
 * under the grid; a tap on a day chooses which. Claimed here:
 *
 *   - the list starts on today, a tap on another day moves it there and opens
 *     nothing, and both + buttons then add to that day;
 *   - on a touch screen wide enough for words, the first tap opens a day's card
 *     and only the second acts on an entry in it;
 *   - the toolbar and the bar above it fit a 320px phone in every view, without
 *     scrolling or overlapping, a week across two months included;
 *   - a dialog is the whole screen below 768px;
 *   - the now line follows the clock.
 *
 * The markup these stand on is asserted in CalendarMonthReadabilityTest.
 */
const VIEWS = ["day", "week", "month", "agenda"] as const;

/**
 * Weeks whose label is the longest it gets, pinned so the claim holds on any
 * day the suite runs: across a month, across a year, and inside one month of a
 * year that is not this one. Today's week alone passed for three weeks of a
 * month and failed the fourth.
 */
const LONG_WEEKS = ["2026-10-26", "2026-12-28", "2030-01-07"] as const;

test.describe("the calendar on a phone", () => {
    test.use({ viewport: { width: 375, height: 760 } });

    // The visit first, for the reason calendar-widths gives: the seed needs a
    // default calendar and the first view is what provisions one.
    test.beforeEach(async ({ page }) => {
        await page.goto("/calendar");
        seed("seed-grid-events");
    });

    test.afterEach(() => seed("seed-grid-events --clear"));

    test("lists the chosen day under the month and adds to it", async ({ page }) => {
        await page.goto("/calendar/month");

        const grid = page.locator('[data-calendar-grid="month"]');
        const shown = page.locator("[data-list-day]:not([hidden])");
        const today = await page.locator("[data-controller~='calendar--now']").getAttribute("data-calendar--now-today-value");

        await expect(shown).toHaveCount(1);
        await expect(shown).toHaveAttribute("data-list-day", today!);
        await expect(grid.locator(`[data-day="${today}"]`)).toHaveAttribute("data-selected", "");

        // Another day of the anchor month: the 15th is in every month's grid
        // and is today only once a month, in which case the 16th.
        const other = today!.endsWith("-15") ? today!.replace(/-15$/, "-16") : today!.replace(/-\d\d$/, "-15");
        await grid.locator(`[data-day="${other}"]`).click();

        await expect(shown).toHaveAttribute("data-list-day", other);
        await expect(grid.locator(`[data-day="${other}"]`)).toHaveAttribute("data-selected", "");
        await expect(page.locator("#modal-backdrop")).toBeHidden();

        await shown.getByRole("button", { name: /New event on/ }).click();
        await expect(page.locator('#modal input[name="startsAt"]')).toHaveValue(new RegExp(`^${other}T`));
        await page.keyboard.press("Escape");
        await expect(page.locator("#modal-backdrop")).toBeHidden();

        // The toolbar's + is reached first, and adds to the same day.
        await page.locator("[data-calendar-new-event]").click();
        await expect(page.locator('#modal input[name="startsAt"]')).toHaveValue(new RegExp(`^${other}T`));
    });

    test("a tap on a bar chooses its day rather than opening it", async ({ page }) => {
        await page.goto("/calendar/month");

        const today = await page.locator("[data-controller~='calendar--now']").getAttribute("data-calendar--now-today-value");
        const bar = page.locator(`[data-calendar-grid="month"] [data-day="${today}"] button[data-event-chip]`).first();
        await bar.click();

        await expect(page.locator("#modal-backdrop")).toBeHidden();
        await expect(page.locator("[data-list-day]:not([hidden])")).toHaveAttribute("data-list-day", today!);
    });

    test("a dialog is the whole screen", async ({ page }) => {
        await page.goto("/calendar/month");
        await page.locator("[data-calendar-new-event]").click();

        const panel = page.locator("[data-ui--modal-panel]");
        await expect(panel).toBeVisible();

        // Polled: the panel opens with a `pop` from 0.985 of its size, and a
        // box read during it is that much short of the screen.
        await expect.poll(async () => {
            const box = await panel.boundingBox();

            return box && [box.x, box.y, box.width, box.height].map(Math.round);
        }).toEqual([0, 0, 375, 760]);
    });
});

test.describe("the calendar on a 320px phone", () => {
    test.use({ viewport: { width: 320, height: 640 } });

    test("the toolbar and the bar above it fit in every view", async ({ page }) => {
        for (const view of [...VIEWS, ...LONG_WEEKS.map((date) => `week/${date}`)]) {
            await page.goto(`/calendar/${view}`);

            const fit = await page.evaluate(() => {
                const label = document.querySelector("[data-calendar-position]")!;
                const row = label.parentElement!;
                const header = document.querySelector("header")!;

                // Every visible control of the bar inside the box of the group
                // it belongs to: the bug was the middle group's icons running
                // out to the left over the logo.
                let spill = 0;
                for (const group of [...header.children]) {
                    const g = group.getBoundingClientRect();
                    for (const item of [...group.children]) {
                        const r = item.getBoundingClientRect();
                        if (0 === r.width || "none" === getComputedStyle(item).display) {
                            continue;
                        }
                        spill = Math.max(spill, g.left - r.left, r.right - g.right);
                    }
                }

                return {
                    labelCut: label.scrollWidth - label.clientWidth,
                    rowOverflow: row.scrollWidth - row.clientWidth,
                    barSpill: Math.round(spill),
                };
            });

            expect(fit, view).toEqual({ labelCut: 0, rowOverflow: 0, barSpill: 0 });
        }
    });
});

test.describe("a month on a touch screen", () => {
    test.use({ viewport: { width: 900, height: 700 }, hasTouch: true, isMobile: true });

    test.beforeEach(async ({ page }) => {
        await page.goto("/calendar");
        seed("seed-grid-events");
    });

    test.afterEach(() => seed("seed-grid-events --clear"));

    test("opens a day on the first tap and an entry on the second", async ({ page }) => {
        await page.goto("/calendar/month");

        const today = await page.locator("[data-controller~='calendar--now']").getAttribute("data-calendar--now-today-value");
        const day = page.locator(`[data-calendar-grid="month"] [data-day="${today}"]`);
        const chip = day.locator("button[data-event-chip]").first();

        await chip.tap();
        await expect(day).toHaveAttribute("data-open", "");
        await expect(page.locator("#modal-backdrop")).toBeHidden();

        await chip.tap();
        await expect(page.locator("#modal-backdrop")).toBeVisible();
    });
});

test.describe("now on a calendar left open", () => {
    test("the line follows the clock", async ({ page }) => {
        await page.clock.install({ time: new Date() });
        await page.goto("/calendar/day");

        const line = page.locator('[data-calendar--now-target="line"]');
        const top = async () => Number.parseFloat((await line.getAttribute("style"))!.replace(/[^0-9.]/g, ""));

        const before = await top();
        await page.clock.runFor(10 * 60_000);

        // Ten minutes is 10/1440 of the day: 0.69 of a percent, give or take
        // the minute the page was drawn in.
        await expect.poll(top).toBeGreaterThan(before + 0.6);
    });
});
