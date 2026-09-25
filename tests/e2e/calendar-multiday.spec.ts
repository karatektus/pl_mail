import { expect } from "@playwright/test";
import { setWhen } from "./support/datetime";
import { test } from "./support/test";
import { APP_TIMEZONE } from "./support/config";

/**
 * An event of a day or more, drawn the way it is read: one bar in the all-day
 * band, its hours shaded in the grid, and one hover for all of it.
 *
 * As it was reported: a trip from Friday 17:00 to Sunday 21:00 was three blocks
 * down three columns, and pointing at one lifted that one alone — Saturday's,
 * titled at a midnight nobody had scrolled to, lifted as a grey column with
 * nothing on it. The layout (DayGridLayout, and its unit test) decides where
 * the pieces go; this is the part only a browser can show, which is that
 * pointing at any piece lights every piece.
 *
 * Created through the dialog rather than seeded, for the reason
 * calendar-timegrid gives: occurrences are materialised on write, and rows put
 * in around the writer can assert a state the app cannot produce.
 */

const TRIP = "E2E multi-day trip";

test("an event of a day or more is one bar, and any piece of it lights all of it", async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });

    // Dates on the PAGE's clock, not UTC's — see calendar-timegrid for the
    // spec that passed all day and failed at 00:20. Today and two days on are
    // in the week view whatever day this runs, because it starts today.
    const day = (offset: number): string => {
        const today = new Intl.DateTimeFormat("en-CA", { timeZone: APP_TIMEZONE }).format(new Date());
        const noon = new Date(`${today}T12:00:00Z`);
        noon.setUTCDate(noon.getUTCDate() + offset);

        return noon.toISOString().slice(0, 10);
    };

    await page.goto("/calendar/week");
    await page.getByRole("button", { name: "New event", exact: true }).click();

    const modal = page.locator("#modal-backdrop");
    await expect(modal).toBeVisible();

    await modal.locator("#event-title").fill(TRIP);
    await setWhen(modal.locator("#event-starts"), `${day(0)}T17:00`);
    await setWhen(modal.locator("#event-ends"), `${day(2)}T21:00`);
    await modal.getByRole("button", { name: "Save" }).click();

    const bar = page
        .locator('[data-calendar--time-grid-target="scroller"] .sticky.top-8 [data-span-key]')
        .filter({ hasText: TRIP });

    await expect(bar).toHaveCount(1);
    await expect(bar).toContainText("until");

    // No block per day any more: the hours are shaded, once on each day.
    await expect(
        page.locator('[data-calendar--time-grid-target="block"]').filter({ hasText: TRIP }),
    ).toHaveCount(0);

    const shading = page.locator(`.span-hatch[data-span-key="${await bar.getAttribute("data-span-key")}"]`);
    await expect(shading).toHaveCount(3);

    // From the bar: every day's shading lights with it, and goes out after.
    await bar.hover();
    await expect(shading.nth(1)).toHaveClass(/\bis-lit\b/);
    await page.mouse.move(5, 5);
    await expect(shading.nth(1)).not.toHaveClass(/\bis-lit\b/);

    // From the middle day's shading, near its top, where no other block can be
    // in the way: the bar lights, and so does the day after.
    await shading.nth(1).hover({ position: { x: 12, y: 12 } });
    await expect(bar).toHaveClass(/\bis-lit\b/);
    await expect(shading.nth(2)).toHaveClass(/\bis-lit\b/);

    // Taken away through the dialog, which is how calendar-timegrid's own
    // dialog-made event goes — and with the same weakness: only when every
    // step above has passed.
    await bar.getByRole("button").click();
    await expect(modal).toBeVisible();
    await modal.getByRole("link", { name: "Edit" }).click();
    await modal.getByRole("button", { name: "Delete" }).click();
    await expect(bar).toHaveCount(0);
});
