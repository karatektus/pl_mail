import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * A week narrower than its own columns pans, and opens where the reader is.
 *
 * Two changes to calendar/_time_grid_shell.html.twig, pinned together because
 * they are the two halves of the same report — a calendar in a 450px window
 * that answered no question it was opened to answer. Sideways, seven columns
 * divided the width into about 45px each and every chip truncated to a letter
 * and an ellipsis. Downwards, the grid opened at 00:00, so the nine empty hours
 * of the night were the first thing on screen and the current time was the last.
 *
 * What is claimed here, and nothing else:
 *
 *   A  a column is never narrower than its floor, and when they do not fit the
 *      GRID grows rather than the columns shrinking,
 *   B  the hour gutter stays where it is while the days pan under it — the one
 *      thing that makes panning readable rather than disorienting,
 *   C  the view opens with now in the upper half of it, at whatever hour the
 *      suite happens to run.
 *
 * Geometry only, and no fixtures, for the reason calendar-heading-fit gives:
 * the grid's own axes are drawn from the date walk alone, and seeding events
 * would add a failure mode that has nothing to do with what is claimed.
 */

/** 6rem, as the template states it. The one number this file hard-codes. */
const COLUMN_FLOOR = 96;

test.describe("a week narrower than its columns", () => {
    // The report's own width, near enough. Seven floored columns want 728 and
    // this is 400, so the grid has something to pan through.
    test.use({ viewport: { width: 400, height: 860 } });

    test("grows the grid rather than shrinking the columns", async ({ page }) => {
        await page.goto("/calendar?view=week");

        const grid = await page.evaluate(() => {
            const scroller = document.querySelector('[data-calendar--time-grid-target="scroller"]')!;
            const hours = document.querySelector('[data-calendar--time-grid-target="hours"]')!;
            const columns = [...document.querySelectorAll("[data-day]")];

            return {
                scrollport: scroller.clientWidth,
                content: hours.getBoundingClientRect().width,
                columns: columns.map((c) => c.getBoundingClientRect().width),
            };
        });

        expect(grid.columns).toHaveLength(7);
        for (const [i, width] of grid.columns.entries()) {
            expect(width, `day ${i} below the floor`).toBeGreaterThanOrEqual(COLUMN_FLOOR);
        }

        // And there is something to pan. Without this the floor could be met by
        // a grid that had quietly stopped drawing a whole week.
        expect(grid.content).toBeGreaterThan(grid.scrollport);
    });

    test("keeps the hour gutter pinned while the days pan under it", async ({ page }) => {
        await page.goto("/calendar?view=week");

        const panned = await page.evaluate(async (floor) => {
            const scroller = document.querySelector('[data-calendar--time-grid-target="scroller"]')!;
            const hours = document.querySelector('[data-calendar--time-grid-target="hours"]')!;

            const read = () => ({
                gutter: hours.firstElementChild!.getBoundingClientRect().left,
                day: document.querySelector("[data-day]")!.getBoundingClientRect().left,
            });

            const before = read();
            scroller.scrollLeft = floor * 2;

            // Two frames, because `scroll-snap-type` is allowed to adjust a
            // programmatic scroll and this asserts against where it settled.
            await new Promise((resolve) =>
                requestAnimationFrame(() => requestAnimationFrame(resolve)),
            );

            return { before, after: read(), scrollLeft: scroller.scrollLeft };
        }, COLUMN_FLOOR);

        // It panned at all — if it did not, the rest of this passes vacuously.
        expect(panned.scrollLeft).toBeGreaterThan(0);

        // Monday went left by however far it panned...
        expect(panned.before.day - panned.after.day).toBeCloseTo(panned.scrollLeft, 0);

        // ...and the gutter did not move, which is the claim.
        expect(panned.after.gutter).toBeCloseTo(panned.before.gutter, 0);
    });
});

test.describe("a chip too small for its own title", () => {
    test.use({ viewport: { width: 450, height: 860 } });

    // The one test in this file that needs a fixture, and it needs an ALL-DAY
    // one: the band is the only place left where a title cannot finish, so it
    // is the only place with anything to prove. Cleared again afterwards, so
    // the geometry tests above keep the empty week their own header promises.
    test.beforeEach(() => seed("seed-grid-events"));

    // Cleared by title after each, the way calendar-timegrid does it, so a test
    // that fails halfway leaves the empty week the tests above are written for.
    test.afterEach(() => seed("seed-grid-events --clear"));

    // Asserted on an ALL-DAY chip rather than on a timed block, and the reason
    // is that the timed block stopped needing it: a block with a height of its
    // own puts its time on one line and up to two of title under it, so at the
    // 96px floor most titles now finish without opening at all. The all-day
    // band is one line tall by definition and has no such room, which makes it
    // the shape that reliably has something left to say.
    test("opens an all-day chip to its content while the pointer is on it", async ({ page }) => {
        await page.goto("/calendar?view=week");

        const chip = page
            .locator('[data-calendar--time-grid-target="scroller"] .sticky.top-8 button')
            .first();
        await expect(chip).toBeVisible();

        const closed = await chip.boundingBox();
        await chip.hover();

        // Polled rather than read once: the growth is a CSS hover state, so
        // there is no event to await and a bare read races the style recalc.
        await expect
            .poll(async () => (await chip.boundingBox())!.width)
            .toBeGreaterThan(closed!.width);

        // And it grew from where it was rather than moving to somewhere new,
        // which is the half of this that a width assertion cannot see.
        const open = await chip.boundingBox();
        expect(Math.round(open!.x)).toBe(Math.round(closed!.x));
        expect(Math.round(open!.y)).toBe(Math.round(closed!.y));
    });
});

test("opens with the current time in view, not midnight", async ({ page }) => {
    await page.goto("/calendar?view=week");

    const view = await page.evaluate(() => {
        const scroller = document.querySelector('[data-calendar--time-grid-target="scroller"]')!;
        const line = document.querySelector('[data-calendar--time-grid-target="nowLine"]');
        if (null === line) return null;

        const port = scroller.getBoundingClientRect();

        return {
            portHeight: port.height,
            below: line.getBoundingClientRect().top - port.top,
            scrollTop: scroller.scrollTop,
            maxScroll: scroller.scrollHeight - scroller.clientHeight,
        };
    });

    // Null would mean this week holds no today, which makes the rest vacuous
    // rather than green — `?view=week` is always the week around now.
    expect(view).not.toBeNull();

    // Written against the hour the suite runs at rather than against a fixed
    // scroll position, because there is no hour at which the claim is not the
    // claim. Before the fix this read as "the whole night, then now, three
    // quarters of the way down", which is a pass on `below > 0` alone.
    expect(view!.below, "the current time is above the top of the view").toBeGreaterThan(0);
    expect(view!.below, "the current time is below the bottom of the view").toBeLessThan(
        view!.portHeight,
    );

    // **A scroller pinned at either end cannot honour the upper-half claim**,
    // and which end it is pinned to depends on the hour the suite runs at.
    //
    // The top clamp was written down here from the start: a run between 00:00
    // and about 03:00 cannot put now any higher than the day begins, and the
    // night above it is real rather than dead space. The bottom clamp is the
    // same arithmetic at the other end and was missed — after about 19:00 there
    // is not a viewport's worth of day left below the line, so scrollTop hits
    // its maximum and the line comes to rest lower than half way however much
    // the grid would like to lift it. This file passed all day and failed at
    // 22:00 with 311px against a 291px bound, which is that gap exactly.
    //
    // Pinned at either end, "visible" above is the whole claim. In between it
    // is the real one.
    const pinnedToTop = 0 === Math.round(view!.scrollTop);
    const pinnedToBottom = Math.round(view!.scrollTop) >= Math.round(view!.maxScroll) - 1;

    if (pinnedToTop) {
        expect(view!.below).toBeLessThanOrEqual(view!.portHeight / 4 + 1);
    } else if (false === pinnedToBottom) {
        expect(view!.below).toBeLessThanOrEqual(view!.portHeight / 2);
    }
});
