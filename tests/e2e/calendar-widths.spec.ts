import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Every view holds together at a phone's width and at a desktop's.
 *
 * The calendar has four views and two shapes, and the things that break when a
 * width changes are not the things a per-view spec is watching. They are the
 * seams: a grid whose headings stop sitting over their own columns, a month
 * cell whose chips start in the day next door, a list whose entries no longer
 * line up, a page that has quietly become scrollable sideways. Each of those
 * has been a real bug here at least once, and none of them is visible from
 * inside the view that has it.
 *
 * So this is a sweep rather than a story: four views, two widths, four
 * invariants, no interaction. 360 is a small phone and the width at which the
 * month gives up its words for bars; 1280 is a desktop page with the sidebar
 * out. The widths between them are covered by the invariants being about
 * RELATIONSHIPS — a heading over its column, a title against its neighbour —
 * rather than about any particular number of pixels.
 *
 * What it deliberately does not do is assert how anything LOOKS. A screenshot
 * suite that fails on a two-pixel shadow teaches everyone to re-record it
 * without reading the diff, and then it is not a test any more.
 */

const VIEWS = ["day", "week", "month", "agenda"] as const;
const WIDTHS = [360, 1280] as const;

test.describe("every view at every width", () => {
    // The visit before the seed is not decoration. `seed-grid-events` puts its
    // fixtures on the user's DEFAULT calendar and exits 1 with "has no default
    // calendar to seed onto" when there is none — and a worker that has drawn
    // no calendar yet has none, because CalendarController provisions it on the
    // first view.
    //
    // Every other spec in the calendar family borrows somebody else's first
    // visit without noticing, which is why this passed locally, where they all
    // run together, and failed on the CI shard that happened to hold this test
    // and nothing else from the family. A spec that only works when it has
    // company is not a spec.
    test.beforeEach(async ({ page }) => {
        await page.goto("/calendar");
        seed("seed-grid-events");
    });

    test.afterEach(() => seed("seed-grid-events --clear"));

    test("keeps its seams together", async ({ page }) => {
        for (const width of WIDTHS) {
            await page.setViewportSize({ width, height: 800 });

            for (const view of VIEWS) {
                await page.goto(`/calendar?view=${view}`);

                const seams = await page.evaluate(() => {
                    const out: Record<string, number> = {};

                    // Nothing makes the PAGE scroll sideways. The week grid pans
                    // inside its own scroller on purpose; the document does not.
                    out.overflow = document.documentElement.scrollWidth - window.innerWidth;

                    // A time-grid heading sits over the column it names. The two
                    // are separate grids sharing one width, so they can drift.
                    const heads = [
                        ...document.querySelectorAll('[data-calendar--time-grid-target="heading"]'),
                    ];
                    const cols = [
                        ...document.querySelectorAll('[data-calendar--time-grid-target="hours"] [data-day]'),
                    ];
                    out.misaligned =
                        heads.length === cols.length
                            ? heads.filter(
                                  (h, i) =>
                                      Math.abs(
                                          h.getBoundingClientRect().x - cols[i].getBoundingClientRect().x,
                                      ) > 1,
                              ).length
                            : -1;

                    // Nothing a month cell draws BEGINS outside it. Where it ends
                    // is the list's business — that box clips, and a chip cut off
                    // at the bottom is the design.
                    out.spill = [...document.querySelectorAll('[data-calendar-grid="month"] [data-day]')]
                        .flatMap((cell) => {
                            const cb = cell.getBoundingClientRect();
                            return [...cell.querySelectorAll("button, a")].filter((el) => {
                                const b = el.getBoundingClientRect();
                                return (
                                    b.width > 0 &&
                                    (b.left < cb.left - 1 || b.left > cb.right + 1 || b.top > cb.bottom + 1)
                                );
                            });
                        }).length;

                    // Every agenda title starts at one x. The lane that lines them
                    // up is a grid track, so one row escaping the grid shows here.
                    const rows = [...document.querySelectorAll("[data-calendar-agenda] button")];
                    out.titleColumns = new Set(
                        rows.map((r) =>
                            Math.round(
                                r.children[r.children.length - 1].getBoundingClientRect().x,
                            ),
                        ),
                    ).size;

                    return out;
                });

                const where = `${view} at ${width}px`;

                expect(seams.overflow, `${where} scrolls sideways`).toBeLessThanOrEqual(1);
                expect(seams.misaligned, `${where} has headings off their columns`).toBe(0);
                expect(seams.spill, `${where} draws a cell's entry outside it`).toBe(0);
                expect(seams.titleColumns, `${where} has ragged agenda titles`).toBeLessThanOrEqual(1);
            }
        }
    });
});
