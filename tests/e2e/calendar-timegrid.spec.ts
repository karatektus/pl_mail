import { expect, type Locator, type Page } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";

/**
 * The time-grid, and moving things on it.
 *
 * These are the cases only a browser can answer. That a drag ends in the right
 * database row is pinned by PHPUnit (EventMoverTest, CalendarTimeGridTest);
 * what those cannot see is whether the gesture reaches the route at all — that
 * the pointer sequence is read as a drag rather than as a click on the chip,
 * that the click the browser fires afterwards does not go on to open the editor
 * over the event that has just moved, that a recurring block stops to ask the
 * question, and that the whole thing is reachable without a mouse.
 *
 * **Fixtures come from a console command, not from the editor.** That is a
 * deliberate reversal of what calendar.spec.ts does, and the reason is that
 * these cases are about the grid: driving the event dialog to create each one
 * makes every test here fail whenever the dialog does, for reasons that have
 * nothing to do with what is being claimed. See
 * App\Command\Test\SeedGridEventsCommand, which also explains why the times it
 * writes are the calendar's wall clock rather than UTC instants.
 *
 * One case is deliberately NOT here: a block on a read-only calendar. Nothing a
 * user can click makes a calendar read-only — it is a property of a mirrored
 * one — so reaching it means seeding a connection as well, and both halves of
 * the behaviour are already covered where they can be reached honestly:
 * CalendarTimeGridTest asserts the block is marked unmovable and that the route
 * refuses a crafted move.
 */

const TIMED = "E2E grid timed";
const ALL_DAY = "E2E grid all day";
const DAILY = "E2E grid daily";

/** The positioned blocks holding one title. */
function blocks(page: Page, title: string) {
    return page
        .locator('[data-calendar--time-grid-target="block"]')
        .filter({ hasText: title });
}

/**
 * The wall time a block says it is at, on the grid's own clock.
 *
 * Read off the attribute rather than off the rendered chip because that is what
 * the drag arithmetic works in, and because the chip prints a 12-hour time with
 * no date on it — two blocks a day apart read identically.
 */
async function startOf(block: Locator) {
    return (await block.getAttribute("data-starts-at")) ?? "";
}

async function endOf(block: Locator) {
    return (await block.getAttribute("data-ends-at")) ?? "";
}

/**
 * Drag from one point on the page to another.
 *
 * `steps` is not optional. The controller ignores travel under a few pixels so
 * that a click stays a click, and a single mouse.move jumps the whole distance
 * in one event — which the browser delivers, but which leaves no intermediate
 * position for anything watching to react to. Several small moves is also what
 * a real drag looks like.
 */
async function dragBy(page: Page, from: { x: number; y: number }, dx: number, dy: number) {
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(from.x + dx, from.y + dy, { steps: 12 });
    await page.mouse.up();
}

/** The centre of a locator, in page coordinates. */
async function centreOf(locator: Locator) {
    const box = (await locator.boundingBox())!;

    return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
}

/**
 * A `YYYY-MM-DDTHH:MM:SS` as a number of hours, so two of them can be
 * subtracted.
 *
 * Through Date.parse of the DATE alone plus the clock read as digits, never
 * Date.parse of the whole stamp: these carry no zone, so a browser reads them
 * as its own local time and the difference between two would be an hour out
 * across a daylight-saving boundary — for a change the user made in wall-clock
 * minutes, which does not cross one.
 */
function hoursOf(stamp: string) {
    const [date, time] = stamp.split("T");
    const [hour, minute] = time.split(":").map(Number);

    return Date.parse(`${date}T00:00:00Z`) / 3_600_000 + hour + minute / 60;
}

/** The clock part alone, for comparing occurrences that are on different days. */
function clockOf(stamp: string) {
    return stamp.split("T")[1];
}

test.describe("calendar time-grid", () => {
    test.beforeEach(() => {
        seed("seed-grid-events");
    });

    test.afterEach(() => {
        // Cleared by title, so a test that failed halfway leaves nothing for the
        // next one to count — and nothing of the user's own is touched, since
        // these live on their default calendar rather than a fixture's.
        seed("seed-grid-events --clear");
    });

    /**
     * The layout choice, from the outside. The page draws hours and positioned
     * blocks; the pane, at the same view, keeps the column list — and asserting
     * the grid is ABSENT there is the half that matters, because a pane that
     * rendered both and hid one would pass a check for only the list.
     */
    test("draws a time-grid on the page and keeps the column list in the pane", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });

        await page.goto("/calendar/week");
        await expect(page.locator('[data-controller="calendar--time-grid"]')).toBeVisible();
        await expect(page.locator('[data-calendar--time-grid-target="column"]')).toHaveCount(7);

        await page.goto("/calendar/week?pane=1");
        await expect(page.locator("turbo-frame#calendar-pane-frame")).toBeVisible();
        await expect(page.locator('[data-controller="calendar--time-grid"]')).toHaveCount(0);
    });

    /**
     * An all-day event has no time to be positioned at, so it is lifted into the
     * band above the hours. Left on the axis it would be a zero-height block on
     * the midnight line: invisible, and a claim that "all day" means "at 00:00".
     */
    test("puts an all-day event in its own row rather than at midnight", async ({ page }) => {
        await page.goto("/calendar/day");

        await expect(page.getByRole("button", { name: new RegExp(ALL_DAY) })).toBeVisible();
        await expect(blocks(page, ALL_DAY)).toHaveCount(0);
    });

    test("dragging a block down the column moves the event later", async ({ page }) => {
        await page.goto("/calendar/day");

        const before = await startOf(blocks(page, TIMED).first());

        // An hour of column is 3rem, so two hours is 96px. Down is later.
        await dragBy(page, await centreOf(blocks(page, TIMED).first()), 0, 96);

        await expect(blocks(page, TIMED).first()).not.toHaveAttribute("data-starts-at", before);
        expect(hoursOf(await startOf(blocks(page, TIMED).first())) - hoursOf(before)).toBe(2);
    });

    /** A week has seven columns, and sideways is another day. */
    test("dragging a block sideways moves the event to another day", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto("/calendar/week");

        const before = await startOf(blocks(page, TIMED).first());
        const column = (await page
            .locator('[data-calendar--time-grid-target="column"]')
            .first()
            .boundingBox())!;

        await dragBy(page, await centreOf(blocks(page, TIMED).first()), column.width, 0);

        await expect(blocks(page, TIMED).first()).not.toHaveAttribute("data-starts-at", before);
        expect(hoursOf(await startOf(blocks(page, TIMED).first())) - hoursOf(before)).toBe(24);
    });

    /**
     * Regression guard: the browser fires a click after every pointer sequence,
     * and it lands on the chip inside the block — whose own action opens the
     * editor. Without the capture-phase suppressor, every drag ended by asking
     * for the dialog of the event that had just been moved.
     *
     * Asserted on the REQUEST and not on the dialog being hidden, and the
     * difference is the whole value of this case. The drag submits a form, so a
     * full navigation lands a moment later and tears the dialog down with the
     * page whether it opened or not — a check on visibility passes either way
     * and guards nothing. The editor is fetched into the modal frame, so asking
     * for it is a GET that either happened or did not.
     *
     * A SHORT drag, and that is not incidental either. A click is dispatched to
     * the nearest common ancestor of where the pointer went down and where it
     * came up, so a long drag ends over the hour lines and produces no click on
     * the chip at all — it would pass with no suppressor in the file. Ten pixels
     * is past the few this controller ignores and still inside the block, which
     * is the case where the click really does land on the chip.
     */
    test("a short drag does not also open the editor", async ({ page }) => {
        await page.goto("/calendar/day");

        const editorRequests: string[] = [];
        page.on("request", (request) => {
            if (/\/calendar\/event\/\d+\/edit/.test(request.url())) {
                editorRequests.push(request.url());
            }
        });

        const before = await startOf(blocks(page, TIMED).first());

        await dragBy(page, await centreOf(blocks(page, TIMED).first()), 0, 10);

        // It was a drag — it moved by one snap — and it opened nothing.
        await expect(blocks(page, TIMED).first()).not.toHaveAttribute("data-starts-at", before);
        expect(editorRequests).toEqual([]);
    });

    /** And a click that is only a click still does. */
    test("a click on a block still opens the editor", async ({ page }) => {
        await page.goto("/calendar/day");

        await blocks(page, TIMED).first().click();

        await expect(page.locator("#modal-backdrop").getByLabel("Title")).toHaveValue(new RegExp(TIMED));
    });

    test("dragging the bottom edge makes the event longer", async ({ page }) => {
        await page.goto("/calendar/day");

        const block = blocks(page, TIMED).first();
        const before = { start: await startOf(block), end: await endOf(block) };
        const box = (await block.boundingBox())!;

        // The grip is the bottom few pixels of the block.
        await dragBy(page, { x: box.x + box.width / 2, y: box.y + box.height - 2 }, 0, 48);

        await expect(blocks(page, TIMED).first()).not.toHaveAttribute("data-ends-at", before.end);

        const after = blocks(page, TIMED).first();
        expect(await startOf(after)).toBe(before.start);
        expect(hoursOf(await endOf(after)) - hoursOf(before.end)).toBe(1);
    });

    /**
     * The question the editor already asks, asked by the grid for the same
     * reason: one occurrence and the whole series are two different changes, and
     * a drag that guessed between them would rewrite a repeating meeting for
     * every day it repeats on.
     */
    test("dragging one occurrence of a series asks which it means", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto("/calendar/week");

        const before = await blocks(page, DAILY).count();
        expect(before).toBeGreaterThan(1);

        const at = await startOf(blocks(page, DAILY).first());

        await dragBy(page, await centreOf(blocks(page, DAILY).first()), 0, 96);

        // The prompt, not a navigation: nothing has been written yet.
        await expect(page.getByRole("button", { name: "This event" })).toBeVisible();
        await expect(page.getByRole("button", { name: "All events" })).toBeVisible();

        await page.getByRole("button", { name: "This event" }).click();

        // The wait, and it has to be this rather than a count: the answer is a
        // form submit and a full Turbo navigation, and every count assertion
        // holds just as well on the page being replaced. `first()` is the block
        // that was dragged, since the series starts today.
        await expect(blocks(page, DAILY).first()).not.toHaveAttribute("data-starts-at", at);
        await expect(blocks(page, DAILY)).toHaveCount(before);

        // Exactly one of them left the hour they all shared.
        const times = await blocks(page, DAILY).evaluateAll((nodes) =>
            nodes.map((node) => node.getAttribute("data-starts-at") ?? ""),
        );

        expect(times.filter((time) => clockOf(time) === clockOf(at))).toHaveLength(before - 1);
    });

    /** The other answer: every occurrence moves together. */
    test("choosing all events moves the whole series", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto("/calendar/week");

        const before = await blocks(page, DAILY).count();
        const at = await startOf(blocks(page, DAILY).first());

        await dragBy(page, await centreOf(blocks(page, DAILY).first()), 0, 96);
        await page.getByRole("button", { name: "All events" }).click();

        // See the note in the previous case: the count alone would pass on the
        // page that is being navigated away from.
        await expect(blocks(page, DAILY).first()).not.toHaveAttribute("data-starts-at", at);
        await expect(blocks(page, DAILY)).toHaveCount(before);

        const times = await blocks(page, DAILY).evaluateAll((nodes) =>
            nodes.map((node) => node.getAttribute("data-starts-at") ?? ""),
        );

        expect(times.filter((time) => clockOf(time) === clockOf(at))).toHaveLength(0);
        expect(new Set(times.map(clockOf)).size).toBe(1);
    });

    /** Abandoning the question abandons the move. */
    test("cancelling the question leaves the series alone", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto("/calendar/week");

        const before = await startOf(blocks(page, DAILY).first());

        await dragBy(page, await centreOf(blocks(page, DAILY).first()), 0, 96);
        await page.getByRole("button", { name: "Cancel" }).click();

        await page.goto("/calendar/week");
        await expect(blocks(page, DAILY).first()).toHaveAttribute("data-starts-at", before);
    });

    /**
     * Dragging is not the only way. Alt with the arrow keys moves a focused
     * block and Enter commits it, so the feature is reachable by anyone who
     * cannot hold a pointer down and travel with it.
     */
    test("moves an event with the keyboard alone", async ({ page }) => {
        await page.goto("/calendar/day");

        const before = await startOf(blocks(page, TIMED).first());

        await blocks(page, TIMED).first().getByRole("button").focus();

        // Four quarter-hours, so the change is an hour and cannot be mistaken
        // for one press of the default snap.
        for (let step = 0; step < 4; step++) {
            await page.keyboard.press("Alt+ArrowDown");
        }

        // Announced before it is committed, because nothing has been written yet
        // and a keyboard user has no block under a cursor to look at.
        await expect(page.locator('[data-calendar--time-grid-target="status"]')).toContainText("Enter");

        await page.keyboard.press("Enter");

        await expect(blocks(page, TIMED).first()).not.toHaveAttribute("data-starts-at", before);
        expect(hoursOf(await startOf(blocks(page, TIMED).first())) - hoursOf(before)).toBe(1);
    });

    /** Shift is the other end of the event, not a bigger step. */
    test("changes the length with the keyboard alone", async ({ page }) => {
        await page.goto("/calendar/day");

        const block = blocks(page, TIMED).first();
        const before = { start: await startOf(block), end: await endOf(block) };

        await block.getByRole("button").focus();

        for (let step = 0; step < 4; step++) {
            await page.keyboard.press("Alt+Shift+ArrowDown");
        }

        await page.keyboard.press("Enter");

        await expect(blocks(page, TIMED).first()).not.toHaveAttribute("data-ends-at", before.end);
        expect(await startOf(blocks(page, TIMED).first())).toBe(before.start);
        expect(hoursOf(await endOf(blocks(page, TIMED).first())) - hoursOf(before.end)).toBe(1);
    });

    /** Escape puts it back, and writes nothing. */
    test("escape abandons a keyboard move", async ({ page }) => {
        await page.goto("/calendar/day");

        const before = await startOf(blocks(page, TIMED).first());

        await blocks(page, TIMED).first().getByRole("button").focus();
        await page.keyboard.press("Alt+ArrowDown");
        await page.keyboard.press("Escape");

        // Enter after Escape opens the editor rather than committing anything,
        // which is what Enter on a block has always done.
        await page.keyboard.press("Enter");
        await expect(page.locator("#modal-backdrop")).toBeVisible();
        await page.keyboard.press("Escape");

        await page.goto("/calendar/day");
        await expect(blocks(page, TIMED).first()).toHaveAttribute("data-starts-at", before);
    });
});
