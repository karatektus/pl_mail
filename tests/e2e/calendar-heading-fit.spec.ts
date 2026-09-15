import { test, expect, type Page } from "./support/test";

/**
 * A day heading stays inside the day it names.
 *
 * The report was "the headers in the week view get too cramped, and the
 * background circle isn't round any more", and both halves of that were one
 * box overflowing. Seven columns divide whatever width the pane has, so at
 * 400px each is about 45 — and "MON", the date and the hover + wanted 68 of
 * them on one line. A flex row does not wrap or clip on its own: it shrinks
 * what it can to min-content and then spills the rest. So the `w-5 h-5` ring
 * around today's date was squeezed to the width of its digits and drawn as an
 * ellipse, and the + button for Monday ended up painted over TUESDAY's
 * heading, where it still opened an editor on Monday.
 *
 * calendar/_time_grid_shell.html.twig answered it with a container query on
 * each heading cell — below 5rem of column the weekday stacked over the date —
 * and it answers it with a FLOOR now: a column is never narrower than 6rem, the
 * grid grows past the scroller instead, and the scroller pans. 96px holds the
 * 89 one line wants, so the stacked layout became unreachable and was removed.
 *
 * Which changes what this file pins, and it is worth being exact about the
 * difference. It used to pin "the heading survives a 45px column". It now pins
 * "no heading is ever given a 45px column, and one line still fits the column
 * it is given". Three things, no more:
 *
 *   A  nothing a heading draws leaves the column it belongs to, at the width
 *      where it used to,
 *   B  today's ring is as wide as it is tall, and not clipped by the scroller
 *      it sits at the top of,
 *   C  the column is at least its floor, and the heading is ONE line — at 400px
 *      as much as at 1280.
 *
 * C is the half that stops A and B passing for the wrong reason: a heading that
 * fits because the grid quietly stopped drawing seven days would pass both.
 *
 * Geometry only, and no fixtures. The heading row is drawn from the date walk
 * alone, so seeding events would add a failure mode that has nothing to do with
 * what is claimed here — and an empty week is the emptiest possible case, which
 * is the one where a heading has the most room and still has to behave.
 */

/** Every day heading, left to right. The gutter column is not one of them. */
function headings(page: Page) {
    return page.locator('[data-calendar--time-grid-target="heading"]');
}

/**
 * The union of every box a heading actually paints, against the cell's own.
 *
 * Read from the rendered boxes rather than from a class list because the bug
 * was never in the classes — the + button carried `ml-auto` and did exactly
 * what it says, in a box too small for the result. Positive numbers mean
 * inside; a negative one is a spill, in pixels, in that direction.
 */
async function fitOf(page: Page, index: number) {
    return page.evaluate((i) => {
        const cells = [...document.querySelectorAll('[data-calendar--time-grid-target="heading"]')];
        const cell = cells[i];
        const box = cell.getBoundingClientRect();

        let left = Infinity, right = -Infinity, top = Infinity, bottom = -Infinity;

        for (const el of cell.querySelectorAll("span, button, i")) {
            const b = el.getBoundingClientRect();
            // The + button is opacity-0 until the day is hovered, which is a
            // paint state and not a layout one: it still occupies its box and
            // still takes the pointer, which is exactly how it reached the day
            // next door. Only a genuinely zero-sized box is skipped.
            if (b.width === 0 && b.height === 0) continue;

            left = Math.min(left, b.left);
            right = Math.max(right, b.right);
            top = Math.min(top, b.top);
            bottom = Math.max(bottom, b.bottom);
        }

        return {
            insetLeft: left - box.left,
            insetRight: box.right - right,
            insetTop: top - box.top,
            insetBottom: box.bottom - bottom,
        };
    }, index);
}

/** Whether a heading is drawn as one line or as two. */
async function directionOf(page: Page, index: number) {
    return page.evaluate((i) => {
        const cells = [...document.querySelectorAll('[data-calendar--time-grid-target="heading"]')];
        return getComputedStyle(cells[i].firstElementChild!).flexDirection;
    }, index);
}

test.describe("week headings", () => {
    test.describe("in a narrow window", () => {
        // 400px is the report's own width. Seven columns used to divide it into
        // about 45px each, which is where one line stopped fitting; they are
        // floored at 96 now and the week pans instead — see
        // calendar-narrow-week.spec.ts, which pins the panning itself.
        test.use({ viewport: { width: 400, height: 860 } });

        test("keeps every heading inside its own day", async ({ page }) => {
            await page.goto("/calendar?view=week");

            const count = await headings(page).count();
            expect(count).toBe(7);

            for (let i = 0; i < count; i++) {
                const fit = await fitOf(page, i);

                // Zero, not a tolerance. A heading that reaches its own border
                // is fine; one that crosses it is over a different day.
                expect(fit.insetLeft, `day ${i} spills left`).toBeGreaterThanOrEqual(0);
                expect(fit.insetRight, `day ${i} spills right`).toBeGreaterThanOrEqual(0);
                expect(fit.insetTop, `day ${i} spills above`).toBeGreaterThanOrEqual(0);
                expect(fit.insetBottom, `day ${i} spills below`).toBeGreaterThanOrEqual(0);

                // One line at the narrowest width the grid can draw, which is
                // the whole point of the floor. Reading it as `row` rather than
                // as "not column" so a third value could never pass quietly.
                expect(await directionOf(page, i), `day ${i} stacked at 400px`).toBe("row");
            }
        });

        test("gives every column at least its floor", async ({ page }) => {
            await page.goto("/calendar?view=week");

            // 6rem, from the template. Asserted on the HEADING cells rather
            // than on the hour columns because they are what has to hold 89px
            // of text, and because a heading that fits a column the hours grid
            // does not share would be the misalignment this shell exists to
            // prevent — calendar-timegrid pins the two against each other.
            const widths = await headings(page).evaluateAll((cells) =>
                cells.map((c) => c.getBoundingClientRect().width),
            );

            expect(widths).toHaveLength(7);
            for (const [i, width] of widths.entries()) {
                expect(width, `day ${i} below the floor`).toBeGreaterThanOrEqual(96);
            }
        });

        test("draws today's date in a circle, whole", async ({ page }) => {
            await page.goto("/calendar?view=week");

            const ring = await page.evaluate(() => {
                const scroller = document.querySelector(
                    '[data-calendar--time-grid-target="scroller"]',
                )!;
                const cell = [
                    ...document.querySelectorAll('[data-calendar--time-grid-target="heading"]'),
                ].find((c) => c.querySelector('span[class*="rounded-full"]'));

                if (!cell) return null;

                const box = cell.querySelector('span[class*="rounded-full"]')!.getBoundingClientRect();
                const port = scroller.getBoundingClientRect();

                return { width: box.width, height: box.height, aboveScrollport: port.top - box.top };
            });

            // Null would mean no column is today's, which makes the rest of
            // this test vacuous rather than green.
            expect(ring).not.toBeNull();

            // Round: one number, both axes. It was 13.7 by 20.
            expect(ring!.width).toBeCloseTo(ring!.height, 1);

            // And whole. `-my-0.5` used to lift it 2px past the top of the
            // scroller, which clips — a ring with a flat top reads as "not
            // round" just as loudly as an oval does.
            expect(ring!.aboveScrollport).toBeLessThanOrEqual(0);
        });
    });

    test.describe("in a wide window", () => {
        test.use({ viewport: { width: 1280, height: 860 } });

        test("still writes the weekday beside the date", async ({ page }) => {
            await page.goto("/calendar?view=week");

            for (let i = 0; i < 7; i++) {
                expect(await directionOf(page, i), `day ${i} stacked at 1280px`).toBe("row");

                const fit = await fitOf(page, i);
                expect(fit.insetLeft).toBeGreaterThanOrEqual(0);
                expect(fit.insetRight).toBeGreaterThanOrEqual(0);
            }
        });
    });
});
