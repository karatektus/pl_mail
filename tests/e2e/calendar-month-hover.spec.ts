import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Hovering a month day enlarges the cell and moves nothing inside it.
 *
 * The report was chips that jumped: in the top rows a hovered day widened where
 * it was, in the bottom two it grew upwards and every chip in it slid up to
 * meet the pointer. The cell is a card now (see _month_shell's
 * `shellCellExpands`) that keeps its top edge always and one side edge, so
 * what is claimed is exactly that:
 *
 *   A  no chip's top changes when the day opens,
 *   B  the card keeps its top and at least one of its sides,
 *   C  it stays inside the grid, and the grid itself does not change size —
 *      a card that pushes a scrollbar into the pane shifts all 42 days.
 *
 * Today's cell, because seed-grid-events puts three entries there and today is
 * wherever the suite happens to run: any row, any column, which over time is
 * every case the anchoring branches on.
 */
test.describe("a month day under the pointer", () => {
    test.use({ viewport: { width: 1280, height: 800 } });

    // The visit first, for the reason calendar-widths gives: the seed needs a
    // default calendar and the first view is what provisions one.
    test.beforeEach(async ({ page }) => {
        await page.goto("/calendar");
        seed("seed-grid-events");
    });

    test.afterEach(() => seed("seed-grid-events --clear"));

    test("enlarges in place without moving its chips", async ({ page }) => {
        await page.goto("/calendar?view=month");

        const measure = () =>
            page.evaluate(() => {
                const grid = document.querySelector('[data-calendar-grid="month"]')!;
                const cell = grid.querySelector("[data-day]:has(.bg-accent.rounded-full)")!;
                const card = cell.firstElementChild!;
                const box = (el: Element) => {
                    const r = el.getBoundingClientRect();
                    return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
                };
                return {
                    grid: box(grid),
                    card: box(card),
                    tops: [...card.querySelectorAll("button[title]")].map((b) =>
                        Math.round(b.getBoundingClientRect().top),
                    ),
                };
            });

        const today = page.locator('[data-calendar-grid="month"] [data-day]').filter({
            has: page.locator(".bg-accent.rounded-full"),
        });
        await expect(today.locator("button[title]").first()).toBeVisible();

        const closed = await measure();
        expect(closed.tops.length).toBeGreaterThan(0);

        await today.hover({ position: { x: 10, y: 10 } });
        // Polled: a CSS hover state has no event to await.
        await expect
            .poll(async () => (await page.evaluate(() => {
                const cell = document.querySelector('[data-calendar-grid="month"] [data-day]:hover');
                return cell ? getComputedStyle(cell.firstElementChild!).boxShadow : "none";
            })))
            .not.toBe("none");

        const open = await measure();

        expect(open.tops, "a chip moved as the day opened").toEqual(closed.tops);
        expect(Math.round(open.card.top)).toBe(Math.round(closed.card.top));
        expect(
            Math.round(open.card.left) === Math.round(closed.card.left) ||
                Math.round(open.card.right) === Math.round(closed.card.right),
            "the card let go of both sides",
        ).toBe(true);

        expect(open.card.left).toBeGreaterThanOrEqual(open.grid.left - 1);
        expect(open.card.right).toBeLessThanOrEqual(open.grid.right + 1);
        expect(open.card.bottom).toBeLessThanOrEqual(open.grid.bottom + 1);
        expect(open.grid, "the grid changed size").toEqual(closed.grid);
    });
});
